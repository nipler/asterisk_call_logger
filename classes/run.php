<?php

define('YII_ENV_DEV', false);

require_once dirname(__FILE__) . '/../../../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../../');
$dotenv->load();


$config = require_once dirname(__FILE__) . '/../../../config/db.php';
$asterisk_config = require_once dirname(__FILE__) . '/../../../config/params.php';
require_once dirname(__FILE__) . '/model.php';
require_once dirname(__FILE__) . '/database.php';

use PAMI\Client\Impl\ClientImpl;


use PAMI\Listener\IEventListener;
use PAMI\Message\Event\EventMessage;
use PAMI\Message\Event\QueueMemberEvent;
use PAMI\Message\Event\AgentsEvent;
use PAMI\Message\Action\QueueStatusAction;
use PAMI\Message\Action\GetVarAction;
use PAMI\Message\Action\ListCategoriesAction;
use PAMI\Message\Action\QueuePauseAction;

ini_set( "display_errors", "on" );
ini_set("default_socket_timeout", -1);
error_reporting( -1 );
        


class Daemon
{
	protected $stop_server = false; // Когда установится в TRUE, демон завершит работу
	protected $currentJobs = array(); // Здесь будем хранить запущенные дочерние процессы

	
	public function __construct()
	{
        
		pcntl_signal(SIGTERM, array($this, "childSignalHandler"));
		pcntl_signal(SIGCHLD, array($this, "childSignalHandler"));
	}
    



	public function run()
	{
		self::tolog("");
		self::tolog("Запущен контроллер демона");

        $this->startReadHandler();
        $this->startWriteHandler();
	}
    
    // запускает обработчик событий астера
	protected function startReadHandler()
	{
		global $config;
		global $asterisk_config;

		$pid = pcntl_fork();
		if ($pid == -1)
		{
			Daemon::tolog("Reader: Невозможно запустить задачу, выход");
			return false;
		}
		elseif ($pid)
		{
			// Этот код выполнится родительским процессом
			$this->currentJobs[$pid] = true;
		}
		else
		{
			// тут крутится обработчик событий астера
			Daemon::tolog("Reader: Запущен pid [".getmypid()."]");
            
            try
            {
                $client = new ClientImpl($asterisk_config['asterisk']);
       
                $client->open();
                $client->registerEventListener(new LoggerRead());
                
                while(!$this->stop_server)
                {
                    usleep(500); 
                    $client->process();
                }
                $client->close(); 
                
            } catch (Exception $e) {
                $client->close(); 
                echo $e->getMessage() . "\n";
                $this->stop_server = true;
            }
        }
    }
    
    // запускает обработку кампаний
	protected function startWriteHandler()
	{
        
        global $config;
        global $asterisk_config;

		$pid = pcntl_fork();
		if ($pid == -1)
		{
			Daemon::tolog("Writer: Невозможно запустить задачу, выход");
			return false;
		}
		elseif ($pid)
		{
			// Этот код выполнится родительским процессом
			$this->currentJobs[$pid] = true;
		}
		else
		{
			// тут крутится обработчик сообщений
			Daemon::tolog("Writer: Запущен pid [".getmypid()."]");
            
            $redis = new Redis();
            $redis->connect('127.0.0.1', 6379);
            $redis->flushAll();
            
            // Получим список агентов
            
            $client = new ClientImpl($asterisk_config['asterisk']);
            $client->open();
            $d_agents = $client->send(new ListCategoriesAction('sip.conf'));
            $sip = $d_agents->getRawContent();
            $siparr = explode("\r\n", $sip);
            $agents = [];
            foreach($siparr as $srow) {
                $srow = explode(":", $srow);
                list($category, $peer) = $srow;
                $peer = trim($peer);
                if(stristr($category, "Category")!==false && is_numeric($peer)) {
                    $agents[] = $peer;
                }
            }
            $logger = new LoggerWrite($agents);
            while (!$this->stop_server) {
                
                $events = $redis->hGetAll('events');
                if(is_array($events) && count($events)>0) {
                    ksort($events);
                    foreach($events as $key=>$event) {
                        $logger->handle($event); 
                        $redis->hDel('events', $key);
                    }
                }
                usleep(500); 
            }
        }
        
    }
    
    public function childSignalHandler($signo, $pid = null, $status = null)
	{
		switch($signo)
		{
			case SIGTERM:
				// При получении сигнала завершения работы устанавливаем флаг
				$this->stop_server = true;
				foreach ($this->currentJobs as $p => $dummy)
					posix_kill($p, SIGTERM);
				break;
			case SIGCHLD:
				// При получении сигнала от дочернего процесса
				if (!$pid)
				{
					$pid = pcntl_waitpid(-1, $status, WNOHANG);
				}
				// Пока есть завершенные дочерние процессы
				while ($pid > 0)
				{
					if ($pid && isset($this->currentJobs[$pid]))
					{
						// Удаляем дочерние процессы из списка
						unset($this->currentJobs[$pid]);
					}
					$pid = pcntl_waitpid(-1, $status, WNOHANG);
				}
				break;
			default:
				// все остальные сигналы
		}
	}
	
	public static function tolog($msg)
	{
		echo date("d.m.Y H:i:s")."\t".$msg.PHP_EOL;
	}
    
}

class LoggerRead implements IEventListener
{
    private $redis;
    
	public function __construct() {
        $this->redis = new Redis();
        $this->redis->connect('127.0.0.1', 6379);
	}
    
    public function __destruct() {
        $this->redis->close();
	}
    
    function getmicrotime() 
    { 
        list($usec, $sec) = explode(" ", microtime()); 
        return ((float)$usec + (float)$sec); 
    } 
        
    public function handle(EventMessage $event)
    {
		// Если событие не пропустили то смотрим параметры
		$event_string = $event->getName();
        //var_dump($event->getRawContent());
		if(!in_array($event_string, array('DialBegin', 'Newchannel', 'BridgeEnter', 'AgentConnect', 'Hangup', 'VarSet', 'BlindTransfer'))) return true;
        $params = $event->getRawContent();
        Daemon::tolog($event_string);
        $this->redis->hSet('events', $this->getmicrotime(), $params);
    }
	
}

class LoggerWrite
{
	
	public $model;
	public $agents = array();
	public $peers = array();
	public $search_arr = [
		'Channel', 'CallerIDNum', 'CallerIDName', 'ConnectedLineNum', 'ConnectedLineName', 'Exten', 'DestChannel', 'DestCallerIDNum', 'DestCallerIDName', 'DestConnectedLineNum', 'DestConnectedLineName', 'DestExten'
	];
	public $phone_ignore = [

    ];
    
    public $trunks = [

    ];
    
    public $params_arr = [];
	
	public $mod_strings = array (
		'ASTERISKLBL_COMING_IN' 		=>	'Входящий звонок',
		'ASTERISKLBL_GOING_OUT' 		=>	'Исходящий звонок',
		'CALL_AUTOMATIC_RECORD'         => '** Авто-запись **',
		'CALL_IN_LIMBO'                 => 'Дозвон',
		'CALL_STATUS_HELD'              => 'Принят',
		'CALL_STATUS_MISSED'            => 'Пропущен',
		'CALL_NAME_CALL'                => 'Разговор',
		'CALL_NAME_MISSED_CLIENT'		=> 'Пропущен клиентом',
		'CALL_NAME_MISSED_OPER'			=> 'Пропущен оператором',
		'CALL_NAME_MISSED_N'			=> 'Недозвон',
		'CALL_DESCRIPTION_CALLER_ID'    => 'Номер звонившего',
		'CALL_DESCRIPTION_MISSED'       => 'Пропущенный/неудачный звонок',
		'CALL_DESCRIPTION_PHONE_NUMBER' => 'Номер телефона'

   ); 
	private $device_states = array // from http://lists.digium.com/pipermail/asterisk-users/2008-December/223892.html
	(
		'UNKNOWN' =>	0,   
		'NOT_INUSE' =>	1,  
		'INUSE' =>		2,    
		'BUSY' =>		3,
		'INVALID' =>	4,    
		'UNAVAILABLE' =>5,   
		'RINGING' =>	6,
		'RINGINUSE' =>	7,   
		'ONHOLD' =>		8,
	);
	public function __construct($agents) {
        $this->model = new Model;
		$this->agents = $agents;
	}
    
    function getmicrotime() 
    { 
        list($usec, $sec) = explode(" ", microtime()); 
        return ((float)$usec + (float)$sec); 
    } 
        
    public function handle($event)
    {
        
        $event_tmp = explode("\r\n", $event);

        $event_arr = array();
        foreach($event_tmp as $e_row) {
            $e_row = trim($e_row);
            if(preg_match("/^([^<]+?:)(.*)$/", $e_row, $find)) {
                $key = str_replace(':', '', trim($find[1]));
                if(isset($find[2])) $value = trim($find[2]);
                else $value = '';
                $event_arr[$key] = $value;
            }
            
        }
		// Если событие не пропустили то смотрим параметры
		$event_string = $event_arr['Event'];
       
		if(!in_array($event_string, array('DialBegin', 'Newchannel', 'BridgeEnter', 'AgentConnect', 'Hangup', 'BlindTransfer'))) return true; 
        
        if($event_string=='BlindTransfer') {

            $linked_id = $event_arr['TransfereeLinkedid'];
            
            $find = $this->model->findCallByAsteriskId($linked_id);
            $params = [];
            $params['id'] = $find['log_id'];
            $params['transferer'] = (!empty($find['transferer'])?$find['transferer']."\r\n":'').preg_replace('/SIP\/([0-9]{2,4}+)-(.*)$/i', '$1', $event_arr['TransfererChannel']).'->'.$event_arr['Extension'];
            $this->model->save('asterisk_log', $params);
        }
        
        
        if(!isset($event_arr['Linkedid'])) $linked_id = null;
        else $linked_id = $event_arr['Linkedid'];
    
        if(!isset($event_arr['Uniqueid'])) $unique_id = null;
		else $unique_id = $event_arr['Uniqueid'];

		if($linked_id===null && $unique_id===null) return false;
		
		$params = $event;
        
		
		if(!$this->model->call_uniq($linked_id, $unique_id, $event_string, $event_arr)) return; 

        
        $time_start = $this->getmicrotime();

		//$params = explode("\n", $params);
		$agent = [];
		$agent_key = [];
		$calerID = [];
		$calerID_key = [];
		$put = "###############################\r\n";
		$put = "===============================\r\n";
		$put .= "Событие: $event_string\r\n";
		$put .= "ID: $linked_id\r\n";
		$put .= "-------------------------------\r\n";
        
        $DestChannel = NULL;
        
		foreach($this->search_arr as $item) {
			if(isset($event_arr[$item]) && $value = $event_arr[$item]) {
				// Ищем agent
				if(preg_match("/[SIP|Local]\/([0-9]{2,4})[-|@]/i", $value, $ext)) {
					$agent_key[] = $item;
					$agent[] = $ext[1];
				}
				elseif(is_numeric($value) && (strlen($value)>=2 && strlen($value) <=4)) {
					$agent_key[] = $item;
					$agent[] = $value;
				}
                elseif(is_numeric($value) && (strlen($value)>=5 && strlen($value) <=13)) {
                    if(!in_array($value, $this->phone_ignore)) {
                        $calerID_key[] = $item;
                        $calerID[] = $value;
                    }
				}
				// Ищем calerID
				elseif(preg_match("/[SIP|Local]*[\/]*([0-9+]{5,13})[-|@]/i", $value, $phone)) {
					if(!in_array($phone[1], $this->phone_ignore)) {
						$calerID[] = $phone[1];
						$calerID_key[] = $item;
					}
					
				}
                
                foreach($this->trunks as $trunk) {
                    if(stristr($value, $trunk) !== false) {
                        $DestChannel = $value;
                        break;
                    }
                }
                
			}
		}
        
		$agent = array_values(array_unique($agent));
		$calerID = array_values(array_unique($calerID));
        
        // Возможно это внутренний номер
        if(count($agent)>1 && !count($calerID)) {
            
            $calerID[0] = $agent[1];
            unset($agent[1]);
            if(is_null($DestChannel)) $DestChannel = 'inner';
        }
		$put .= "-------------------------------\r\n";
		$put .= "Agent: ".implode(", ",$agent)." (".implode(", ",$agent_key).")\r\n";
		$put .= "Номер: ".implode(", ",$calerID)." (".implode(", ",$calerID_key).")\r\n";
		$put .= "===============================\r\n\r\n";
		echo $put;
		//file_put_contents(dirname(__FILE__).'/log.txt', $put, FILE_APPEND);
		
		// ========================== НАХОДИМ ДРУГИЕ ПАРАМЕТРЫ ЗВОНКА ===============================
		// Определить контакт
		$contact_id = NULL;
		$tmpCallerID = NULL;
		if(isset($calerID[0])) {
			$tmpCallerID = $calerID[0];
            $tmpCallerID = preg_replace('/[^0-9]/', '', $tmpCallerID);
		}
		
		// Определить оператора
		$assigned_user_id = NULL;
		$Channel = NULL;
		
        $direction = NULL;
		$callDirection = NULL;
		$callName = NULL;
        
        if(isset($agent[0])) {
            $assigned_user_id = $this->model->findOperatorCallByChanel($agent[0]);
            $this->peers[$agent[0]] = $assigned_user_id;
        }
        
        if(count($agent_key)) {
            $Channel = $event_arr[$agent_key[0]];
            if(stristr($Channel, 'SIP') === false) {
                $Channel = 'SIP/'.$Channel.'-000';
            }
        }
        
        if($event_string=='Newchannel') {
            if((string)$linked_id == (string)$unique_id) {
                
                if(preg_match("/SIP\/([\d]{3,4})[-|@](.+?)$/", $event_arr['Channel'])) {
                    $direction = 'O';
                    $callDirection = 'Outbound';
                    $callName      = $this->mod_strings['ASTERISKLBL_GOING_OUT'];
                    
                }
                else {
                    $direction = 'I';
                    $callDirection = 'Inbound';
                    $callName      = $this->mod_strings['ASTERISKLBL_COMING_IN'];
                }
            }
            else {
                //$Channel = $event_arr['Channel'];
            }
        }
        
        if($event_string=='BridgeEnter' && isset($agent[0]) && (string)$linked_id == (string)$unique_id) {
            if(in_array($agent[0], [101, 102, 103, 104])) {
                global $asterisk_config;
                $a = new ClientImpl($asterisk_config['asterisk']);
                $a->open();
                $result = $a->send(new QueuePauseAction('SIP/'.$agent[0]));
                $a->close(); 
                echo "Ставим на паузу ".$agent[0]."\r\n";
            }
            
        }
		
		

		// Получим данные
		$find = $this->model->findCallByAsteriskId($linked_id);
        
        if(empty($find['call_contact_id']) && strlen($tmpCallerID) >= 5) $contact_id = $this->model->findContactByPhoneNumber($tmpCallerID);
		
		$failedCall = FALSE;
		$callStart = NULL;
		$callFinish = NULL;
		
		$callDescription = "";
		$callDurationRaw = 0; 
		$callStatus      = 'In Limbo';
		$timestampHangup = NULL;
		$callstate = NULL;
		
		if($event_string == 'Hangup' ) {
			$hangupTime      = time();
			$callStatus = 'Held';
			$timestampHangup = date('Y-m-d H:i:s');
			// Если был разговор
			if (!empty($find['log_timestampLink']))
			{
				$callStart = $find['log_timestampLink'];
				$callStartLink   = strtotime($find['log_timestampLink']);
				$callDurationRaw = $hangupTime - $callStartLink;
			}
			else
			{
				$failedCall = TRUE;
				$callStart = date('Y-m-d H:i:s');
			}

			$callFinish = date('Y-m-d H:i:s');
			
			if (!$failedCall)
			{
				if($find['call_name']==$this->mod_strings['CALL_IN_LIMBO'] || $find['call_name']==$this->mod_strings['CALL_AUTOMATIC_RECORD']) $callName = $this->mod_strings['CALL_STATUS_HELD'];
				else $callName = $find['call_name'];
				$callDescription   = $find['call_description'];
			}
			else
			{
				// Если не был Dial
				if (empty($find['log_timestampCall']))
				{
					$callStart = date('Y-m-d H:i:s');
					$callFinish = date('Y-m-d H:i:s');
					$callDurationRaw = 0;
					$callName = $this->mod_strings['CALL_NAME_MISSED_N'];
					$callStatus      = 'Congestion';
					$timestampHangup = NULL;
					$callstate = 'NeedID';
				}
				else {
					if($find['call_direction']=='Outbound') $callName = $this->mod_strings['CALL_NAME_MISSED_CLIENT'];
					elseif($find['call_direction']=='Inbound') $callName = $this->mod_strings['CALL_NAME_MISSED_OPER'];
					else $callName        = $this->mod_strings['CALL_DESCRIPTION_MISSED'];
					$callStatus      = 'Missed';
					
					$callDescription = "Пропущен ({$event_arr['Cause-txt']})\n";
				}
			}
            
            /* $this->model->unset_call_uniq($linked_id, $unique_id);
            unset($params_arr['Hangup'][$unique_id]);
            
            if(count($params_arr['Hangup']) == 0) {
                $this->model->unset_call_uniq($linked_id);
                echo "/////////////////////// Все каналы закрыты \\\\\\\\\\\\\\\\\\\\\\\\ \r\n";
            } */
            
		}
		else
		{
			if (!empty($find['log_timestampLink']))
			{
				$hangupTime      = time();
				$callStart = $find['log_timestampLink'];
				$callStartLink   = strtotime($find['log_timestampLink']);
				$callDurationRaw = $hangupTime - $callStartLink;
				$callStatus      = 'Talk';
			}
		}
		
		// ==============  СОХРАНЯЕМ ИНФУ =========================//

       
		$params = array(
			'name'=>(!empty($callName)?$callName:(isset($find['call_name']) && !empty($find['call_name'])?$find['call_name']:NULL)),
			'created_by'=>1,
			'status'=>$callStatus,
			'direction'=>(!empty($callDirection)?$callDirection:(isset($find['call_direction']) && !empty($find['call_direction'])?$find['call_direction']:NULL)),
			'operator_id'=>(!empty($assigned_user_id)?$assigned_user_id:(isset($find['call_operator_id']) && !empty($find['call_operator_id'])?$find['call_operator_id']:NULL)),
			'duration'=>$callDurationRaw,
			'date_start'=>$callStart,
			'date_end'=>$callFinish,
			'description'=>(!empty($callDescription)?$callDescription:(isset($find['call_description']) && !empty($find['call_description'])?$find['call_description']:NULL)),
            'callerid'=>(isset($find['call_callerid']) && !empty($find['call_callerid'])?$find['call_callerid']:$tmpCallerID),
            'contact_id'=>(isset($find['call_contact_id']) && !empty($find['call_contact_id'])?$find['call_contact_id']:$contact_id),
            'created_at'=>(isset($find['call_created_at']) && !empty($find['call_created_at'])?$find['call_created_at']:date('Y-m-d H:i:s')),
		); 
		if(isset($find['call_id'])) $params['id'] = $find['call_id'];
		$callRecordId = $this->model->save('calls', $params);

		$params = array(
			'call_id'=>$callRecordId,
			'asterisk_id'=>$linked_id,
			'callstate'=>$event_string,
			'callerID'=>(!empty($tmpCallerID)?$tmpCallerID:(isset($find['log_callerID']) && !empty($find['log_callerID'])?$find['log_callerID']:NULL)),
			'channel'=>(!empty($Channel)?$Channel:(isset($find['log_channel']) && !empty($find['log_channel'])?$find['log_channel']:NULL)),
			'remote_channel'=>(!empty($DestChannel)?$DestChannel:(isset($find['log_remote_channel']) && !empty($find['log_remote_channel'])?$find['log_remote_channel']:NULL)),
			'timestampCall'=>(isset($find['log_timestampCall']) && !empty($find['log_timestampCall'])?$find['log_timestampCall']:($event_string=='Newchannel'?date('Y-m-d H:i:s'):NULL)),
			'timestampLink'=>(isset($find['log_timestampLink']) && !empty($find['log_timestampLink'])?$find['log_timestampLink']:($event_string=='BridgeEnter'?date('Y-m-d H:i:s'):NULL)),
			'timestampHangup'=>$timestampHangup,
			'direction'=>(isset($find['log_direction']) && !empty($find['log_direction'])?$find['log_direction']:$direction),
			'contact_id'=>(isset($find['log_contact_id']) && !empty($find['log_contact_id'])?$find['log_contact_id']:$contact_id),
		);
		if(isset($find['log_id'])) $params['id'] = $find['log_id'];
		$this->model->save('asterisk_log', $params);

    }
	
}
    