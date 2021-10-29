
<?php
	require('traffic-api.php');

	define('API_KEY', '1234567890abcdef');
	define('RECIPIENTS', '37129222222');
	define('SENDER', 'Client');

    function error_output(TrafficAPI $APIObject)
	{
		if ($APIObject -> ErrNo)
		{
			echo 'Error #'.$APIObject -> ErrNo.': '.$APIObject -> Error;
		}
	}

	function results_output($Data)
	{
		echo '<pre>'.print_r($Data, 1).'</pre>';
		echo '<hr />';
	}

	function debug_output(TrafficAPI $APIObject)
	{
		echo '<pre>'.print_r($APIObject -> Debug, 1).'</pre>';
	}

    $TrafficAPI = new TrafficAPI(API_KEY);

    // Retrieving Senders
	echo '<h2>Senders</h2>';
	$Senders = $TrafficAPI -> GetSenders();
	debug_output($TrafficAPI);
	error_output($TrafficAPI);
	results_output($Senders);

	// Send message
	echo '<h2>Send message</h2>';
	$SendMessage = $TrafficAPI -> Send(RECIPIENTS, SENDER, 'Hello World!');
	debug_output($TrafficAPI);
	error_output($TrafficAPI);
	results_output($SendMessage);

	// Retrieving Delivery
	echo '<h2>Delivery</h2>';
	$Delivery = $TrafficAPI -> GetDelivery(array_keys($SendMessage));
	debug_output($TrafficAPI);
	error_output($TrafficAPI);
	results_output($Delivery);

	// Retrieving Report
	echo '<h2>Report</h2>';
	$Report = $TrafficAPI -> GetReport(array_keys($SendMessage));
	debug_output($TrafficAPI);
	error_output($TrafficAPI);
	results_output($Report);
    
