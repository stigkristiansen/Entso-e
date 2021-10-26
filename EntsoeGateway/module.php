<?php

declare(strict_types=1);

include __DIR__ . "/../libs/traits.php";

class EntsoeGateway extends IPSModule {
	use WebCall;

	const RATES_BASE_URL = 'http://api.exchangeratesapi.io/v1/latest';
	const DAY_AHEAD_BASE_URL = 'https://transparency.entsoe.eu/api';

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

		foreach($requests as $request) {
		
			if(!isset($request->Function)||!isset($request->ChildId)) {
				throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "Function" and/or "ChildId" is missing. The request was "%s"', $request));
			}
			
			if(!isset($request->RequestId)) {
				throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "RequestId" is missing. The request was "%s"', $request));
			}

			$function = strtolower($request->Function);
			$childId =  $request->ChildId;
			$requestId = $request->RequestId;
			
			switch($function) {
				case 'getdayaheadprices':
					if(!isset($request->Area)) {
						throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "Area" is missing. The request was "%s"', $request));
					}
					
					$this->GetDayAheadPrices($request->Area, $childId, $requestId);
					break;
				case 'getexchangerates':
					if(!isset($request->Currency)) {
						throw new Exception(sprintf('HandleAsyncRequest: Invalid formated request. Key "Currency" is missing. The request was "%s"', $request));
					}		

					$this->GetExchangeRates($request->Currency, $childId, $requestId);
					break;
				default:
					throw new Exception(sprintf('HandleAsyncRequest failed. Unknown function "%s"', $function));
			}
		}
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
		
		if(!isset($xml->{"TimeSeries"}->{"Period"}->{"Point"})) {
			throw new Exception('Failed to call Entso-e. Invalid data, missing "Point"');
		}

		$currency = (string)$xml->{"TimeSeries"}->{"currency_Unit.name"};
		$priceMeasureUnitName = (string)$xml->{"TimeSeries"}->{"price_Measure_Unit.name"};
		
		$points = [];
		foreach($xml->{"TimeSeries"}->{"Period"}->{"Point"} as $point) {
			$points[] = (float)((string)$point->{"price.amount"});
		}    
		   
		$series = array('Currency' => $currency);
		$series['MeasureUnit'] = $priceMeasureUnitName;
		$series['Points'] = $points;
	

		$return = array('Function' => 'GetDayAheadPrices');
		$return['RequestId'] = $RequestId;
		$return['Result'] = $series;
		
		$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Returning day-Ahead Prices to requesting child with Ident %s. Result sent is %s...',  $ChildId, json_encode($return)), 0);
		$this->SendDataToChildren(json_encode(["DataID" => "{6E413DE8-C9F0-5E7F-4A69-07993C271FDC}", "ChildId" => $ChildId, "RequestId" => $RequestId,"Buffer" => $return]));
	}

	private function GetExchangeRates(string $Currency, string $ChildId, string $RequestId) {
		$this->SendDebug(IPS_GetName($this->InstanceID), 'Requesting Exchange rate....', 0);

		$apiKey = $this->ReadPropertyString('RatesApiKey');
		if(strlen($apiKey)==0) {
			throw new Exception('Missing API key for Exchangerates.io');
		}

		$params = array('access_key' => $apiKey);
		$params['base'] = $Currency;
		$params['symbols'] = 'NOK,SEK,DKK';

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

		$return = array('Function' => 'GetExchangeRates');
		$return['RequestId'] = $RequestId;
		$return['Result'] = $result->result;
		
		$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Returning Exchange rates to requesting child with Ident %s. Result sent is %s...',  $ChildId, json_encode($return)), 0);
		$this->SendDataToChildren(json_encode(["DataID" => "{6E413DE8-C9F0-5E7F-4A69-07993C271FDC}", "ChildId" => $ChildId, "Buffer" => $return]));
	}
}