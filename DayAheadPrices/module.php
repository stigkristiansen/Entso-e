<?php

declare(strict_types=1);
	
include __DIR__ . "/../libs/traits.php";

class DayAheadPrices extends IPSModule {
	use utility;
	use profiles;

	public function Create() {
		//Never delete this line!
		parent::Create();

		$this->ConnectParent('{751B2290-5D65-1759-A970-5B7CA5CAAA7A}');

		$this->RegisterPropertyString('Area', '10YNO-1--------2');
		$this->RegisterPropertyString('EntsoECurrency', 'EUR');
		$this->RegisterPropertyString('ReportCurrency', 'NOK');

		$this->RegisterAttributeString('Prices', '');
		$this->RegisterAttributeString('Rates', '');
		
		$this->RegisterProfileFloat('ESEDA.Price', 'Dollar', '', ' price/kWt', 4);

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
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('ReqestAction called for Ident "%s" with Value %s', $Ident, (string)$Value), 0);

			switch (strtolower($Ident)) {
				case 'refresh':
					$this->Refresh();						
					break;
				default:
					throw new Exception(sprintf('ReqestAction called with unkown Ident "%s"', $Ident));
			}
		} catch(Exception $e) {
			$this->LogMessage(sprintf('RequestAction failed. The error was "%s"',  $e->getMessage()), KL_ERROR);
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('RequestAction failed. The error was "%s"', $e->getMessage()), 0);
		}
	}	
	
	private function Refresh() {
		$this->SetTimerInterval('EntoseDayAheadRefresh' . (string)$this->InstanceID, 3600*1000);

		$this->HandleData();
	}

	private function RequestData(bool $Rates, bool $Prices) {
		$guid = self::GUID();
		$request = [];
		
		if($Rates) {
			$currency = $this->ReadPropertyString('EntsoECurrency');
			$request[] = ['Function'=>'GetExchangeRates', 'RequestId'=>$guid, 'ChildId'=>(string)$this->InstanceID, 'Currency'=>$currency];
		}
		if($Prices) {
			$area = $this->ReadPropertyString('Area');
			$request[] = ['Function'=>'GetDayAheadPrices', 'RequestId'=>$guid, 'ChildId'=>(string)$this->InstanceID, 'Area'=>$area];
		}

		if(count($request)>0) {
			$this->SendDataToParent(json_encode(['DataID' => '{8ED8DB86-AFE5-57AD-D638-505C91A39397}', 'Buffer' => $request]));
		}
	}

	private function HandleData() {
		$fetchPrices = $this->EvaluateAttribute('Prices');
		$fetchRates  = $this->EvaluateAttribute('Rates');

		if($fetchPrices||$fetchRates) {
			$this->RequestData($fetchRates, $fetchPrices);
		} else {
			$this->UpdateVariables();
		}
	}

	private function UpdateVariables() {
		$data = json_decode($this->ReadAttributeString('Prices'));
		$rates =  json_decode($this->ReadAttributeString('Rates'));

		switch(strtolower($data->Prices->MeasureUnit)) {
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
				$rate = $rates->Rates->rates->NOK;
				break;
			case $this->ReadPropertyString('EntsoECurrency'):
				$rate = 1;
				break;
			case 'SEK':
				$rate = $rates->Rates->rates->SEK;
				break;
			case 'DKK':
				$rate = $rates->Rates->rates->DKK;
				break;
		}
		
		$reportedCurrency = $data->Prices->Currency;

		$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Prices from Entso-e are reported in %s', $reportedCurrency), 0);
		if($this->ReadPropertyString('EntsoECurrency')!=$this->ReadPropertyString('ReportCurrency')) {
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('1 %s is %s %s', $this->ReadPropertyString('EntsoECurrency'), (string)$rate, $this->ReadPropertyString('ReportCurrency')), 0);
		}
		$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Variables show prices in %s', $this->ReadPropertyString('ReportCurrency')), 0);
		$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Divider is: %s', (string)$divider), 0);
		
		$stats = $this->GetStats($data->Prices->Points);

		if($reportedCurrency!=$this->ReadPropertyString('EntsoECurrency')) {
			$this->LogMessage(sprintf('There is a mismatch between Entso-e configured currency (%s )and received currency (%s). Please reconfigure!', $this->ReadPropertyString('EntsoECurrency'), $reportedCurrency), KL_ERROR);
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('There is a mismatch between Entso-e configured currency (%s )and received currency (%s). Please reconfigure!', $this->ReadPropertyString('EntsoECurrency'), $reportedCurrency), 0);

			return;
		}

		$this->SendDebug(IPS_GetName($this->InstanceID), 'Updating variables...', 0);
		$this->SetValue('Current', $stats->current/$divider*$rate);
		$this->SetValue('High', $stats->high/$divider*$rate);
		$this->SetValue('Low', $stats->low/$divider*$rate);
		$this->SetValue('Avg', $stats->avg/$divider*$rate);
		$this->SetValue('Median', $stats->median/$divider*$rate);
		
	}

	private function EvaluateAttribute(string $Name) {
		$fetchData = false;

		$now = new DateTime('Now');
		$today = $now->format('Y-m-d');

		$data = $this->ReadAttributeString($Name);
		if(strlen($data)>0) {
			$day = json_decode($data);

			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Data in attribute "%s" is "%s"', $Name, $data), 0);
			
			if(isset($day->Date)) {
				if($day->Date!=$today) {
					$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Attribute "%s" has old data! Fetching new data', $Name), 0);
					$fetchData = true;						
				}
			} else {
				$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Attribute "%s" has invalid data! Fetching new data', $Name), 0);
				$fetchData = true;
			}
		} else {
			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Attribute "%s" is empty! Fetching new data', $Name), 0);
			$fetchData = true;
		}

		return $fetchData;
	}

	public function ReceiveData($JSONString) {
		$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Received data from parent. The data is %s', $JSONString), 0);
		$data = json_decode($JSONString);

		if(isset($data->Buffer->Function)) {
			$function = strtolower($data->Buffer->Function);
			switch($function) {
				case 'getexchangerates':
					$rates = $data->Buffer->Result;
					$this->UpdateRates($rates);
					return;
				case 'getdayaheadprices':
					$prices = $data->Buffer->Result;
					$this->UpdatePrices($prices);
					$this->UpdateVariables();
					return;
				default:
					$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Unsupported function "%s"', $function), 0);
					return;
			}
		}

		$this->SendDebug(IPS_GetName($this->InstanceID), 'Invalid data received from parent', 0);

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
		$this->SendDebug(IPS_GetName($this->InstanceID), 'Calculating statistics...', 0);
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

		$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Calculated statistics: %s', json_encode($stats)), 0);

		return (object)$stats;
		
	}
}