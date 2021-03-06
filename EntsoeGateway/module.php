<?php

declare(strict_types=1);

include __DIR__ . "/../libs/traits.php";


class EntsoEGateway extends IPSModule {
	use WebCall;

	const RATES_BASE_URL = 'http://api.exchangeratesapi.io/v1/latest';
	const DAY_AHEAD_BASE_URL = 'https://transparency.entsoe.eu/api';
	const GRAPHS_BASE_URL = 'https://quickchart.io';

	public function Create() {
		//Never delete this line!
		parent::Create();

		$this->RegisterPropertyString('ApiKey', '');
		$this->RegisterPropertyString('RatesApiKey', '');

		$this->RegisterPropertyBoolean('SkipSSLCheck', false);
	}

	public function Destroy() {
		//Never delete this line!
		parent::Destroy();
	}

	public function ApplyChanges() {
		//Never delete this line!
		parent::ApplyChanges();
	}

	public function RequestAction($Ident, $Value) {
		try {
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('ReqestAction called for Ident "%s" with Value %s', $Ident, $Value), 0);

			switch (strtolower($Ident)) {
				case 'async':
					$this->HandleAsyncRequest($Value);
					break;
				default:
					throw new Exception(sprintf('ReqestAction called with unkown Ident "%s"', $Ident));
			}
		} catch(Exception $e) {
			$this->LogMessage(sprintf('RequestAction failed. The error was "%s"',  $e->getMessage()), KL_ERROR);
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('RequestAction failed. The error was "%s"', $e->getMessage()), 0);
		}
	}

	public function ForwardData($JSONString) {
		$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Received a request from a child. The request was "%s"', $JSONString), 0);

		$data = json_decode($JSONString);
		$requests = json_encode($data->Buffer);
		$script = "IPS_RequestAction(" . (string)$this->InstanceID . ", 'Async', '" . $requests . "');";

		$this->SendDebug(IPS_GetName($this->InstanceID), 'Executing the request(s) in a new thread...', 0);
				
		// Call RequestAction in another thread
		IPS_RunScriptText($script);

		return true;
	}

	private function HandleAsyncRequest(string $Requests) {
		$requests = json_decode($Requests);


		try {
			foreach($requests as $request) {
			
				if(!isset($request->Function)||!isset($request->ChildId)) {
					throw new Exception(sprintf('Incoming request is invalid. Key "Function" and/or "ChildId" is missing. The request was "%s"', $Requests));
				}
				
				if(!isset($request->RequestId)) {
					throw new Exception(sprintf('Incoming request is invalid. Key "RequestId" is missing. The request was "%s"', $Requests));
				}

				$function = strtolower($request->Function);
				$childId =  $request->ChildId;
				$requestId = $request->RequestId;
				
				switch($function) {
					case 'getdayaheadprices':
						if(!isset($request->Area)) {
							throw new Exception(sprintf('Incoming request is invalid. Key "Area" is missing. The request was "%s"', $Requests));
						}

						$this->GetDayAheadPrices($request->Area, $childId, $requestId);
						break;
					case 'getdayaheadpricesgraph':
						if(!isset($request->Points)) {
							throw new Exception(sprintf('Incoming request is invalid. Key "Points" is missing. The request was "%s"', $Requests));
						}

						if(!isset($request->File)) {
							throw new Exception(sprintf('Incoming request is invalid. Key "File" is missing. The request was "%s"', $Requests));
						}

						if(!isset($request->Date)) {
							throw new Exception(sprintf('Incoming request is invalid. Key "Date" is missing. The request was "%s"', $Requests));
						}
						
						$this->GetDayAheadPricesGraph($request->Points, $request->Date, $request->File, $childId, $requestId);
						break;
					default:
						throw new Exception(sprintf('Incoming request failed. Unknown function "%s"', $function));
				}
			}
		} catch(Exception $e) {
			$buffer = array('Error' => true, 'Message' => $e->getMessage());
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('HandleAsyncRequest() failed. The error was "%s"', $e->getMessage()), 0);
			if(isset($request->ChildId)) {
				$requestId= isset($request->RequestId)?$request->RequestId:'Unknown';
				$this->SendDataToChildren(json_encode(["DataID" => "{6E413DE8-C9F0-5E7F-4A69-07993C271FDC}", "ChildId" => $request->ChildId, "RequestId" => $requestId,"Buffer" => $buffer]));
			}
		}
	}

	private function GetDayAheadPricesGraph(array $Points, string $Date, string $File, string $ChildId, string $RequestId) {
		$this->SendDebug(IPS_GetName($this->InstanceID), 'Downloading DayAheadPrices Graph...', 0);
		
		$max = count($Points);
		for($i=0;$i<$max;$i++) {
			$hours[]=$i;
		}
		
		$chart = array('type' => 'line');
		$chart['data'] = array('labels' => $hours);
		$chart['data']['datasets'] = array(array('label' => $Date, 'data' => $Points));

		$url = self::GRAPHS_BASE_URL . '/chart?bkg=white&c=' . urlencode(json_encode($chart));

		$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('The url is "%s"', $url), 0);

		$this->DownloadURL($url, urldecode($File));

		$this->SendDebug(IPS_GetName($this->InstanceID), 'The graph was downloaded', 0);

		$return = array('Function' => 'GetDayAheadPricesGraph', 'File'=> $File);
		$this->SendDataToChildren(json_encode(["DataID" => "{6E413DE8-C9F0-5E7F-4A69-07993C271FDC}", "ChildId" => $ChildId, "RequestId" => $RequestId,"Buffer" => $return]));
	}

	private function GetDayAheadPrices(string $Area, string $ChildId, string $RequestId) {
		$this->SendDebug(IPS_GetName($this->InstanceID), 'Requesting Day-Ahead prices....', 0);

		$apiKey = $this->ReadPropertyString('ApiKey');
		if(strlen($apiKey)==0) {
			throw new Exception('Missing API key for Entso-e');
		}

		$midnight = new DateTime('today midnight');
		$midnight->setTimezone(new DateTimeZone("UTC")); 
		$periodStart = $midnight->format('YmdHi');
		$midnight->add(new DateInterval('PT24H'));
		$periodEnd = $midnight->format('YmdHi');

		$zone = $Area;
		$params = array('securityToken' => $apiKey);
		$params['in_Domain'] = $zone;
		$params['out_Domain'] = $zone;
		$params['periodStart'] = $periodStart;
		$params['periodEnd'] = $periodEnd;
		$params['documentType'] = 'A44';

		$result = $this->Request('get', self::DAY_AHEAD_BASE_URL, $params);

		if($result->success==false) {
			throw new Exception(sprintf('Failed to call Entso-e. The error was %s:%s', (string)$result->httpcode, $result->errortext));
		}

		$xml = simplexml_load_string($result->result);

		if(!isset($xml->{"TimeSeries"}->{"currency_Unit.name"})) {
			throw new Exception('Failed to call Entso-e. Invalid data, missing "TimeSeries"');
		}
		
		if(!isset($xml->{"TimeSeries"}->{"price_Measure_Unit.name"})) {
			throw new Exception('Failed to call Entso-e. Invalid data, missing "price_Measure_Unit.name"');
		}

		if(!isset($xml->{"TimeSeries"}->{"Period"}->{"resolution"})) {
			throw new Exception('Failed to call Entso-e. Invalid data, missing "resolution"');
		}
		
		if(!isset($xml->{"TimeSeries"}->{"Period"}->{"Point"})) {
			throw new Exception('Failed to call Entso-e. Invalid data, missing "Point"');
		}


		$resolution = (string)$xml->{"TimeSeries"}->{"Period"}->{"resolution"};
		$currency = (string)$xml->{"TimeSeries"}->{"currency_Unit.name"};
		$priceMeasureUnitName = (string)$xml->{"TimeSeries"}->{"price_Measure_Unit.name"};
		
		$points = [];
		foreach($xml->{"TimeSeries"}->{"Period"}->{"Point"} as $point) {
			$points[] = (float)((string)$point->{"price.amount"});
		}    
		   
		$series = array('Currency' => $currency);
		$series['MeasureUnit'] = $priceMeasureUnitName;
		$series['Resolution'] = $resolution;
		$series['Points'] = $points;
	
		$return = array('Function' => 'GetDayAheadPrices');
		$return['RequestId'] = $RequestId;
		$return['Prices'] = $series;
		$return['Rates'] = $this->GetExchangeRates($currency);
				
		$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Returning day-Ahead Prices to requesting child with Ident %s. Result sent is %s...',  $ChildId, json_encode($return)), 0);
		$this->SendDataToChildren(json_encode(["DataID" => "{6E413DE8-C9F0-5E7F-4A69-07993C271FDC}", "ChildId" => $ChildId, "RequestId" => $RequestId,"Buffer" => $return]));
	}

	private function GetExchangeRates(string $Currency) { 
		$this->SendDebug(IPS_GetName($this->InstanceID), 'Requesting Exchange rate....', 0);

		$apiKey = $this->ReadPropertyString('RatesApiKey');
		if(strlen($apiKey)==0) {
			throw new Exception('Missing API key for Exchangerates.io');
		}

		$params = array('access_key' => $apiKey);
		$params['base'] = $Currency;
		$params['symbols'] = 'NOK,SEK,DKK,EUR';

		$result = $this->Request('get', self::RATES_BASE_URL, $params);

		if($result->success==false) {
			throw new Exception(sprintf('Failed to call Exchangerates.io. The error was %s:%s', (string)$result->httpcode, $result->errortext));
		}

		if(!isset($result->result->success)) {
			throw new Exception(sprintf('Exchangerates.io returned invalid data. The returend data was %s', json_encode($result->result)));
		}

		if($result->result->success==false) {
			throw new Exception(sprintf('Call to Exchangerates.io failed.The error was %s:%s',$result->result->error->code, $result->result->error->info));
		}

		return $result->result;
	}
}