<?php

class Model {
	
    
    private $db;
    
    public function __construct() {
		$this->db = new DB;
	}
	
	public function findCallByAsteriskId($asteriskId) {

		$query = "SELECT 
            b.id AS 'call_id',
            b.name AS 'call_name',
            b.created_by AS 'call_created_by',
            b.status AS 'call_status',
            b.direction AS 'call_direction',
            b.operator_id AS 'call_operator_id',
            b.duration AS 'call_duration',
            b.date_start AS 'call_date_start',
            b.date_end AS 'call_date_end',
            b.description AS 'call_description',
            b.callerid AS 'call_callerid',
            b.contact_id AS 'call_contact_id',
            b.created_at AS 'call_created_at',
            
            a.id AS 'log_id',
            a.asterisk_id AS 'log_asterisk_id',
            a.callstate AS 'log_callstate',
            a.callerID AS 'log_callerID',
            a.channel AS 'log_channel',
            a.remote_channel AS 'log_remote_channel',
            a.timestampCall AS 'log_timestampCall',
            a.timestampLink AS 'log_timestampLink',
            a.timestampHangup AS 'log_timestampHangup',
            a.direction AS 'log_direction',
            a.contact_id AS 'log_contact_id'
        FROM asterisk_log a LEFT JOIN calls b ON a.call_id = b.id WHERE a.asterisk_id='$asteriskId'";
		$result = $this->db->query($query);
		$logResult = $result->fetch();
		
		if ($logResult === null) {
			return FALSE;
		}

		return $logResult;
	}
	
	
	// Поиск ответсвенного за звонок
	public function findOperatorCallByChanel($ext) {
	
		$query = "SELECT user.id, user.username 
        FROM user 
        JOIN user_profile ON user.id = user_profile.user_id WHERE 
		user_profile.sip_number='$ext' LIMIT 0,1";
		$result = $this->db->query($query);
		$row = $result->fetch();

		if ($row!==null)
		{
			return $row['id'];
		}
		return false;
	}
	
	public function findContactByPhoneNumber($aPhoneNumber) {
		 
        //var_dump($aPhoneNumber);
		$aPhoneNumber = preg_replace('/[^0-9]/', '', $aPhoneNumber);
		if(preg_match("/^(8+)(.+?)$/",$aPhoneNumber,$rus)) {
			$aPhoneNumber = $rus[2];
		}
        elseif(preg_match("/^(7+)(.+?)$/",$aPhoneNumber,$rus)) {
			$aPhoneNumber = $rus[2];
		}
        if(trim($aPhoneNumber) == '') return false;
        
		$query = "SELECT * FROM contacts WHERE 
        phone_mobile LIKE '%$aPhoneNumber'
        OR phone_home LIKE '%$aPhoneNumber'
        OR phone_work LIKE '%$aPhoneNumber'
        OR phone_other LIKE '%$aPhoneNumber'
        ";
		$result = $this->db->query($query);
		$row = $result->fetch();

		return (isset($row['id'])?$row['id']:null);
	}

	
	
	public function deleteCall($callRecordId) {
		$query = "DELETE FROM calls WHERE id='$callRecordId'";
		$this->db->query($query);
		
		$query = "DELETE FROM asterisk_log WHERE call_record_id='$callRecordId'";
		$this->db->query($query);
	}
	
	public function save($table, $params) {
		$id = false;
		$r_id = false;
		$w_value = false;
		$sql_set = array();
		foreach($params as $key=>$value) {
			if($key == 'id') {
				$id = $key;
				$w_value = $value;
				$r_id = $value;
				
				continue;
			}
			$sql_set[] = " `$key` = ".(empty($value)?"NULL":"'$value'")." ";
		}
		if($id) {
			$sql = "UPDATE $table SET ";
		}
		else {
			$sql = "INSERT INTO $table SET ";
		}
		$sql .= implode(", ", $sql_set);
		if($id) {
			$sql .= " WHERE $id = '$w_value' ";
		}
        
        //Daemon::tolog($sql);
		$res = $this->db->query($sql);
		if(!$r_id) {
			$r_id = $this->db->lastInsertId();
		}
		return $r_id;
	}
    
    
    
    public function call_uniq($linked_id, $unique_id, $event_string, $params) {
        $check = $this->db->query("SELECT * FROM asterisk_dial_event WHERE asterisk_id = '$linked_id' AND asterisk_dest_id = '$unique_id' AND event = '$event_string'");
        $row = $check->fetch();
       
		if(!$row) {
			$sql = "INSERT INTO asterisk_dial_event (event, asterisk_id, asterisk_dest_id, params, date) VALUES ('$event_string', '$linked_id', '$unique_id', '".json_encode($params)."', '".date('Y-m-d H:i:s')."')";
			$this->db->query($sql);
            return true;
		}
        return false;
    }
    
    public function unset_call_uniq($linked_id, $unique_id = '') {
        $check = $this->db->query("DELETE FROM asterisk_dial_event WHERE asterisk_id = '$linked_id'".($unique_id!=''?" AND asterisk_dest_id = '$unique_id'":""));
    }
	
}


?>