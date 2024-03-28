<?php

declare(strict_types=1);
	
include __DIR__ . "/../libs/traits.php";

class DayAheadPrices extends IPSModule {
	use utility;
	use profiles;
	use Media;

	public function Create() {
		//Never delete this line!
		parent::Create();

		$this->ConnectParent('{751B2290-5D65-1759-A970-5B7CA5CAAA7A}');

		$this->RegisterPropertyString('Area', '10YNO-1--------2');
		$this->RegisterPropertyString('ReportCurrency', 'NOK');
		$this->RegisterPropertyString('DateFormat', 'd.m.Y');
		$this->RegisterPropertyInteger('VAT', 25);

		$this->RegisterAttributeString('Prices', '');
		$this->RegisterAttributeString('Rates', '');
		
		$this->RegisterProfileFloat('ESEDA.Price', 'Dollar', '', ' price/kWt', 2);

		$this->RegisterTimer('EntoseDayAheadRefresh' . (string)$this->InstanceID, 0, 'IPS_RequestAction(' . (string)$this->InstanceID . ', "Refresh", 0);'); 

		$this->RegisterVariableFloat('Current', 'Current', 'ESEDA.Price', 1);
		$this->RegisterVariableFloat('Low', 'Low', 'ESEDA.Price', 2);
		$this->RegisterVariableFloat('High', 'High', 'ESEDA.Price', 3);
		$this->RegisterVariableFloat('Avg', 'Average', 'ESEDA.Price', 4);
		$this->RegisterVariableFloat('Median', 'Median', 'ESEDA.Price', 5);

		$this->RegisterMessage(0, IPS_KERNELMESSAGE);
	}

	public function Destroy() {
		$module = json_decode(file_get_contents(__DIR__ . '/module.json'));
		if(count(IPS_GetInstanceListByModuleID($module->id))==0) {
			$this->DeleteProfile('ESEDA.Price');	
		}

		//Never delete this line!
		parent::Destroy();
	}

	public function ApplyChanges() {
		//Never delete this line!
		parent::ApplyChanges();

		$this->SetReceiveDataFilter('.*"ChildId":"' . (string)$this->InstanceID .'".*');

		switch($this->ReadPropertyString('ReportCurrency')) {
			case 'NOK':
			case 'SEK':
			case 'DKK':
				$profileSuffix = 'kr/kWh';
				break;
			case 'EUR':
				$profileSuffix = '€/kWh';
				break;
			default:
				$profileSuffix = 'price/kWh';
		}
		
		IPS_SetVariableProfileText('ESEDA.Price', '', ' '.$profileSuffix);
		
		if (IPS_GetKernelRunlevel() == KR_READY) {
			$this->InitTimer();
		}

		$this->HandleData();
	}

	public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
		parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

		if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
			$this->InitTimer();
		}
	}

	public function RequestAction($Ident, $Value) {
		try {
			$this->SendDebug(__FUNCTION__, sprintf('ReqestAction called for Ident "%s" with Value %s', $Ident, (string)$Value), 0);

			switch (strtolower($Ident)) {
				case 'refresh':
					$this->Refresh();						
					break;
				default:
					throw new Exception(sprintf('ReqestAction called with unkown Ident "%s"', $Ident));
			}
		} catch(Exception $e) {
			$this->LogMessage(sprintf('RequestAction failed. The error was "%s"',  $e->getMessage()), KL_ERROR);
			$this->SendDebug(__FUNCTION__, sprintf('RequestAction failed. The error was "%s"', $e->getMessage()), 0);
		}
	}	

	public function ReceiveData($JSONString) {
		$data = json_decode($JSONString);

		if(isset($data->Buffer->Error)) {
			$this->LogMessage(sprintf('Received an error from the gateway. The error was "%s"',  $data->Buffer->Message), KL_ERROR);
			$this->SendDebug(__FUNCTION__, sprintf('Received an error from the gateway. The error was "%s"', $data->Buffer->Message), 0);
			return;
		}

		$this->SendDebug(__FUNCTION__, sprintf('Received data from the gateway. The data is %s', $JSONString), 0);

		if(isset($data->Buffer->Function)) {
			$function = strtolower($data->Buffer->Function);
			switch($function) {
				case 'getdayaheadprices':
					$prices = $data->Buffer->Prices;
					$rates = $data->Buffer->Rates;
					$this->UpdatePrices($prices);
					$this->UpdateRates($rates);
					$this->UpdateVariables();
					$this->UpdateGraph();
					$this->SendDebug(__FUNCTION__, 'GetDayAheadPrices completed successfully', 0);
					return;
				case 'getdayaheadpricesgraph':
					$file = urldecode($data->Buffer->File);
					
					$id = $this->CreateMediaByName($this->InstanceID, 'DayAheadPrices', 1, 'DayAheadPrices');
					if($id!==false) {
						IPS_SetMediaFile($id, $file, false);
					}
					
					$this->SendDebug(__FUNCTION__, 'GetDayAheadPricesGraph completed successfully', 0);
					return;
				default:
					$this->SendDebug(__FUNCTION__, sprintf('Unsupported function "%s"', $function), 0);
					return;
			}
		}

		$this->SendDebug(__FUNCTION__, 'Invalid data received from parent', 0);

	}
	
	private function Refresh() {
		//$this->SetTimerInterval('EntoseDayAheadRefresh' . (string)$this->InstanceID, 3600*1000);
		$this->InitTimer();

		$this->HandleData();
	}

	private function RequestData() {
		$guid = self::GUID();
		$request = [];
	
		$area = $this->ReadPropertyString('Area');
		$request[] = ['Function'=>'GetDayAheadPrices', 'RequestId'=>$guid, 'ChildId'=>(string)$this->InstanceID, 'Area'=>$area];
	
		$this->SendDataToParent(json_encode(['DataID' => '{8ED8DB86-AFE5-57AD-D638-505C91A39397}', 'Buffer' => $request]));
	}

	private function HandleData() {
		$fetchPrices = $this->EvaluateAttribute('Prices');
		$fetchRates  = $this->EvaluateAttribute('Rates');
		$fetchGraph =  !file_exists(__DIR__ . '/../../../media/DayAheadGraph.png');

		if($fetchPrices||$fetchRates||$fetchGraph) {
			$this->RequestData();
		} else {
			$this->UpdateVariables();
		}
	}

	private function GetFactors($Prices, $Rates) {
		switch(strtolower($Prices->Prices->MeasureUnit)) {
			case 'wh':
				$divider = 0.001;
				break;
			case 'kwh':
				$divider = 1;
				break;
			case 'mwh': 
				$divider = 1000;
				break;
			case 'gwh':
				$divider = 1000000;
				break;
		}

		switch($this->ReadPropertyString('ReportCurrency')) {
			case 'NOK':
				$rate = $Rates->Rates->rates->NOK;
				break;
			case 'EUR':
				$rate = $rates->Rates->rates->EUR;
				break;
			case 'SEK':
				$rate = $rates->Rates->rates->SEK;
				break;
			case 'DKK':
				$rate = $rates->Rates->rates->DKK;
				break;
		}

		return (object)array('Divider'=>$divider, 'Rate'=>$rate);
	}

	public function UpdateGraph() {
		$prices = json_decode($this->ReadAttributeString('Prices'));
		$rates =  json_decode($this->ReadAttributeString('Rates'));
		
		$factors = $this->GetFactors($prices, $rates);
		$divider = $factors->Divider;
		$rate = $factors->Rate;
		$vat = 1 + $this->ReadPropertyInteger('VAT')/100;

		$max = count($prices->Prices->Points);
		for($i=0;$i<$max;$i++) {
			$points[] = $prices->Prices->Points[$i]/$divider*$rate*$vat;
		}

		$guid = self::GUID();
		$file = urlencode(__DIR__ . '/../../../media/DayAheadGraph.png');

		$now = new DateTime('Now');
		$today = $now->format($this->ReadPropertyString('DateFormat'));
		
		
		$request[] = ['Function'=>'GetDayAheadPricesGraph', 'RequestId'=>$guid, 'ChildId'=>(string)$this->InstanceID, 'Points'=>$points, 'File'=>$file, 'Date'=>$today];
		$this->SendDataToParent(json_encode(['DataID' => '{8ED8DB86-AFE5-57AD-D638-505C91A39397}', 'Buffer' => $request]));

	}

	private function UpdateVariables() {
		$prices = json_decode($this->ReadAttributeString('Prices'));
		$rates =  json_decode($this->ReadAttributeString('Rates'));
		
		$factors = $this->GetFactors($prices, $rates);
		$divider = $factors->Divider;
		$rate = $factors->Rate;
		$vat = 1 + $this->ReadPropertyInteger('VAT')/100;

		$entsoeCurrency = $prices->Prices->Currency;
		$this->SendDebug(__FUNCTION__, sprintf('Prices from Entso-e are reported in %s', $entsoeCurrency), 0);
		
		$this->SendDebug(__FUNCTION__, sprintf('Variables show prices in %s', $this->ReadPropertyString('ReportCurrency')), 0);
		
		if($entsoeCurrency!=$this->ReadPropertyString('ReportCurrency')) {
			$this->SendDebug(__FUNCTION__, sprintf('1 %s is %s %s', $entsoeCurrency, (string)$rate, $this->ReadPropertyString('ReportCurrency')), 0);
		}
		
		$this->SendDebug(__FUNCTION__, sprintf('Divider is: %s', (string)$divider), 0);
		
		$stats = $this->GetStats($prices->Prices->Points);

		$this->SendDebug(__FUNCTION__, 'Updating variables...', 0);
		$this->SetValue('Current', $stats->current/$divider*$rate*$vat);
		$this->SetValue('High', $stats->high/$divider*$rate*$vat);
		$this->SetValue('Low', $stats->low/$divider*$rate*$vat);
		$this->SetValue('Avg', $stats->avg/$divider*$rate*$vat);
		$this->SetValue('Median', $stats->median/$divider*$rate*$vat);
		
	}

	private function EvaluateAttribute(string $Name) {
		$fetchData = false;

		$now = new DateTime('Now');
		$today = $now->format('Y-m-d');

		$data = $this->ReadAttributeString($Name);
		if(strlen($data)>0) {
			$day = json_decode($data);

			$this->SendDebug(__FUNCTION__, sprintf('Data in attribute "%s" is "%s"', $Name, $data), 0);
			
			if(isset($day->Date)) {
				if($day->Date!=$today) {
					$this->SendDebug(__FUNCTION__, sprintf('Attribute "%s" has old data! Fetching new data', $Name), 0);
					$fetchData = true;						
				}
			} else {
				$this->SendDebug(__FUNCTION__, sprintf('Attribute "%s" has invalid data! Fetching new data', $Name), 0);
				$fetchData = true;
			}
		} else {
			$this->SendDebug(__FUNCTION__, sprintf('Attribute "%s" is empty! Fetching new data', $Name), 0);
			$fetchData = true;
		}

		return $fetchData;
	}

	private function UpdateRates(object $Rates) {
		$now = new DateTime('Now');
		$today = $now->format('Y-m-d');

		$rates = array('Date' => $today);
		$rates['Rates'] = $Rates;
		$this->WriteAttributeString('Rates', json_encode($rates));
	}

	private function UpdatePrices(object $Prices) {
		$now = new DateTime('Now');
		$today = $now->format('Y-m-d');

		$prices = array('Date' => $today);
		$prices['Prices'] = $Prices;
		$this->WriteAttributeString('Prices', json_encode($prices));
	}

	private function InitTimer() {
		$this->SetTimerInterval('EntoseDayAheadRefresh' . (string)$this->InstanceID, (self::SecondsToNextHour()+1)*1000); 
	}

	private function GetStats($Prices) {
		$this->SendDebug(__FUNCTION__, 'Calculating statistics...', 0);
		$date = new DateTime('Now');
		$currentIndex = $date->format('G');
		
		$stats = array('current' => (float)$Prices[$currentIndex]);
		
		sort($Prices, SORT_NUMERIC);
		
		$stats['high'] = (float)$Prices[count($Prices)-1];
		$stats['low'] = (float)$Prices[0];
		$stats['avg'] = (float)(array_sum($Prices)/count($Prices));

		$count = count($Prices);
		$index = floor($count/2);

		$stats['median'] = $count%2==0?(float)($Prices[$index-1]+$Prices[$index])/2:(float)$Prices[$index];

		$this->SendDebug(__FUNCTION__, sprintf('Calculated statistics: %s', json_encode($stats)), 0);

		return (object)$stats;
	}

	public function GetIntervalWithLowestPrice(int $Timeframe) : array {
		if($Timeframe < 1 || $Timeframe > 24) {
			throw new Exception('Invalid parameter "Timeframe". Timeframe must be grater than 0 and less than 25'); 
		}

		$this->SendDebug(__FUNCTION__, 'Calculating the lowest price interval...', 0);

		$prices = json_decode($this->ReadAttributeString('Prices'));
		$points = $prices->Prices->Points;
		
		$lowestIdx = 0;
		$lowestSum = PHP_FLOAT_MAX;

		for($idx=0;$idx<=24-$Timeframe; $idx++) {
			$tempSum = 0;
			$endIdx = $idx+$Timeframe;
			
			for($idx2=$idx;$idx2<$endIdx;$idx2++) {
				$tempSum += $points[$idx2];
			}

			if($tempSum < $lowestSum) {
				$lowestSum = $tempSum;
				$lowestIdx = $idx;
			}
		}

		for($idx=$lowestIdx;$idx<$lowestIdx+$Timeframe;$idx++) {
			$lowestPrices[$idx] = $points[$idx];
		}

		$stats = $this->GetStats($lowestPrices);
		unset($stats['current']);
		
		$result['interval'] = array('start'=>$lowestIdx, 'end'=>$lowestIdx+$Timeframe-1);
		$result['prices'] = $lowestPrices;
		$result['statistics'] = $stats;

		$this->SendDebug(__FUNCTION__, sprintf('The lowest price interval is: %s', json_encode($result)), 0);

		return $result;

	}

}