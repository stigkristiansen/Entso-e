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

		$this->RegisterPropertyString('Area', 'NO1');

		$this->RegisterAttributeString('Day', '');
		
		$this->RegisterProfileFloat('ESEDA.Price', 'Dollar', '', ' kr/kWt', 4);

		$this->RegisterTimer('EntoseDayAheadRefresh' . (string)$this->InstanceID, 0, 'IPS_RequestAction(' . (string)$this->InstanceID . ', "Refresh", 0);'); 

		$this->RegisterVariableFloat('Current', 'Aktuell', 'ESEDA.Price', 1);
		$this->RegisterVariableFloat('Low', 'Lavest', 'ESEDA.Price', 2);
		$this->RegisterVariableFloat('High', 'Høyest', 'ESEDA.Price', 3);
		$this->RegisterVariableFloat('Avg', 'Gjennomsnitt', 'ESEDA.Price', 4);
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

	private function RequestData() {
		$guid = self::GUID();
		$request[] = ['Function'=>'GetExchangeRates', 'RequestId'=>$guid, 'ChildId'=>(string)$this->InstanceID];
		$request[] = ['Function'=>'GetDayAheadPrices', 'RequestId'=>$guid, 'ChildId'=>(string)$this->InstanceID];
		
		$this->SendDataToParent(json_encode(['DataID' => '{8ED8DB86-AFE5-57AD-D638-505C91A39397}', 'Buffer' => $request]));
	}

	private function HandleData() {
		$fetchData = false;

		$now = new DateTime('Now');
		$today = $now->format('Y-m-d');

		$data = $this->ReadAttributeString('Day');
		if(strlen($data)>0) {
			$day = json_decode($data);

			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Data in attribute "Day" is "%s"', $data), 0);
			
			if(isset($day->date)) {
				if($day->date!=$today) {
					$this->SendDebug(IPS_GetName($this->InstanceID), 'Attribute "Day" has old data! Fetching new data', 0);
					$fetchData = true;						
				}
			} else {
				$this->SendDebug(IPS_GetName($this->InstanceID), 'Attribute "Day" has invalid data! Fetching new data', 0);
				$fetchData = true;
			}
		} else {
			$this->SendDebug(IPS_GetName($this->InstanceID), 'Attribute "Day" is empty! Fetching new data', 0);
			$fetchData = true;
		}

		if($fetchData) {
			$this->RequestData();
			return;
		}

		$day = json_decode($data);
			

	}

	public function ReceiveData($JSONString) {
		$data = json_decode($JSONString);
		IPS_LogMessage('Device RECV', utf8_decode($data->Buffer));
	}

	private function InitTimer() {
		$this->SetTimerInterval('EntoseDayAheadRefresh' . (string)$this->InstanceID, (self::SecondsToNextHour()+1)*1000); 
	}



	
}