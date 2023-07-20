<?php

class RestoreTrash
{
    private $uri;
    private $username;
    private $password;
    private $sabreService;
    private $restoreDate;
    private $trashbinData;
    private $connections;

    public function __construct($uri, $username, $password, $restoreDate, $connections)
    {
        $this->trashbinData = [];
        $this->sabreService = new Sabre\Xml\Service();
        $this->uri = $uri;
        $this->username = $username;
        $this->password = $password;
        $this->restoreDate = new DateTime($restoreDate);
	    $this->connections = $connections;
    }

    public function run()
    {
        echo("Collect files to restore...\n");
        $this->collectTrashbinData();
        echo(sprintf("Found %s files to restore \n", count($this->trashbinData)));

        $this->restoreTrashbinData();
    }

    private function collectTrashbinData()
    {
        $ch = curl_init();

        $curlOptions = [
            CURLOPT_FAILONERROR => 1,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL => $this->uri . "/remote.php/dav/trashbin/" . $this->username . "/trash",
            CURLOPT_USERPWD => "$this->username:$this->password",
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_CUSTOMREQUEST => "PROPFIND",
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/xml',
                'Connection: Keep-Alive',
                'charset=UTF-8',
                'Depth: 1',
            ],
            CURLOPT_POSTFIELDS => '<?xml version="1.0"?>
                                   <d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns" xmlns:ns="http://nextcloud.org/ns">
                                       <d:prop>
                                           <ns:trashbin-filename />
                                           <ns:trashbin-original-location />
                                           <ns:trashbin-deletion-time />
                                           <d:getcontentlength />
								           <d:getlastmodified />
                                           <d:resourcetype />
                                       </d:prop>
                                   </d:propfind>'
            ];


        curl_setopt_array($ch, $curlOptions);
        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            echo(sprintf("ERROR: %s\n",curl_error($ch)));
        }

        curl_close($ch);

        $data = $this->sabreService->parse($response);
        array_shift($data);

	    foreach ($data as $value) {
            $remoteUrl = $value['value'][0]['value'];

	        $fileName = $value['value'][1]['value'][0]['value'][0]['value'];
	        $originalPath = $value['value'][1]['value'][0]['value'][1]['value'];
	        $deletionDatetime = new DateTime();
	        $deletionDatetime->setTimestamp($value['value'][1]['value'][0]['value'][2]['value']);

            $lastModified = DateTime::createFromFormat('D, d M Y H:i:s e', trim($value['value'][1]['value'][0]['value'][0]['value']));
	        //Only observe data which has been deleted after certain date
            if ($deletionDatetime < $this->restoreDate || str_ends_with($remoteUrl, '/')) {
                continue;
            }
            $this->trashbinData[] = [
                'remoteUrl' => $remoteUrl,
		        "deletionDatetime" => $deletionDatetime,
		        "originalPath" => $originalPath,
		        "originalFileName" => $fileName
            ];
        }
    }

    private function restoreTrashbinData()
    {
	    $startDate = new DateTime();
	    $ignoreURLStart = strlen('/remote.php/dav/trashbin/' . $this->username . '/trash/');
	    $dataCount = count($this->trashbinData);
	    $iterations = $dataCount/$this->connections;
	    for($iteration = 0; $iteration < $iterations; $iteration++){
	    	echo("Restore next ".$this->connections." files...\n");
	    	unset($multiHandler);
	    	$multiHandler = curl_multi_init();
	    	$curlHandles = [];
	    	$currentFiles = [];
            // Init connections
	    	for($connection = 0; $connection < $this->connections; $connection++){
	    		if($iteration * $this->connections + $connection >= $dataCount)
	    			break;
	    		$currentFile = $this->trashbinData[$iteration * $this->connections + $connection];
	    		echo(substr($currentFile['remoteUrl'], $ignoreURLStart)." | path: ".$currentFile['originalPath']." | deleted: ".$currentFile['deletionDatetime']->format('Y-m-d H:i:s')."\n");
	    		unset($curlHandle);
	    		$curlHandle = curl_init();
	    		$curlOptions = [
	    			CURLOPT_FAILONERROR => 1,
	    			CURLOPT_RETURNTRANSFER => false,
	    			CURLOPT_URL => $this->uri . $currentFile['remoteUrl'],
	    			CURLOPT_USERPWD => "$this->username:$this->password",
	    			CURLOPT_SSL_VERIFYHOST => 0,
	    			CURLOPT_SSL_VERIFYPEER => 0,
	    			CURLOPT_CUSTOMREQUEST => "MOVE",
	    			CURLOPT_HTTPHEADER => [
	    			    //'Overwrite: F',
	    			    'Destination: ' . $this->uri . '/remote.php/dav/trashbin/' . $this->username . '/restore/'.substr($currentFile['remoteUrl'], $ignoreURLStart),
	    		]];
	    		$curlHandles[] = $curlHandle;
	    		curl_setopt_array($curlHandle, $curlOptions);
	    		curl_multi_add_handle($multiHandler, $curlHandle);
	    	}
	    	echo("\n");

            // Run Requests
	    	do {
	    	    $status = curl_multi_exec($multiHandler, $active);
	    	    if ($active) {
	    		// Einen Moment auf weitere Aktivität warten
	    		curl_multi_select($multiHandler);
	    	    }
	    	} while ($active && $status == CURLM_OK);

            // Close connections
	    	foreach($curlHandles as $curlHandle)
	    		curl_multi_remove_handle($multiHandler, $curlHandle);

            // ----- Short log:
	    	if ($status != CURLM_OK) {
	    		$log = sprintf("ERROR: %s\n",curl_multi_strerror($status));
	    		$log .= "Affected files may be: ".print_r($currentFiles, true)."\n";
	    		file_put_contents('./log_'.date("j.n.Y").'.log', $log, FILE_APPEND);
                    	echo($log);
            }
	    	curl_multi_close($multiHandler); // Not needed anymore
	    	for($connection = 0; $connection < $this->connections; $connection++){
	    		if($iteration * $this->connections + $connection >= $dataCount)
                                    break;
	    		$currentFile = $this->trashbinData[$iteration * $this->connections + $connection];
	    		echo(sprintf("File %s restored\n", substr($currentFile['remoteUrl'], $ignoreURLStart )));
	    	}
	    	$interval = (new DateTime())->diff($startDate);
	    	echo((($iteration+1)*$this->connections)." Files restored\n. Running for ".
                sprintf('%d:%02d:%02d',($interval->d * 24) + $interval->h,$interval->i,$interval->s)."\n".
                ($dataCount - ($iteration+1)*$this->connections)." Files left to restore.\n\n");
	    }
   }
}
