# Логирование звонков Asterisk в БД с ипользованием библиотеки PAMI

Используется многопоточность через модуль pcntl. Один процесс занимается считыванием нужных событий из сокета Asterisk в redis. Второй читает из редис и пишет в БД.

Запуск `./startdaemon.sh`
Отсановка `./killdaemon.sh`