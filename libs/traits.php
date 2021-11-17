<?php

trait WebCall {
    private function Request($Type, $Url, $Params=[], $Headers = [], $PostFields=[]) {
		$ch = curl_init();
		
		switch(strtolower($Type)) {
			case 'put':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
				break;
			case 'post':
				curl_setopt($ch, CURLOPT_POST, true );
				break;
			case 'get':
                // Default for cURL
		    	break;
            case 'delete':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
		}

        if(count($PostFields)>0) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $Data);
        } else {
            $Headers[] = 'Content-Length:0';
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $Headers);

		$skipSSLCheck = $this->ReadPropertyBoolean('SkipSSLCheck');
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, !$skipSSLCheck?0:2);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$skipSSLCheck);
		
		$url =  $Url . '?'  . http_build_query($Params);
        curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true );
	    
        $this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Calling %s', $url ), 0);

		$result = curl_exec($ch);

        $response = array('httpcode' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE));
		
		if($result===false) {
			$response['success'] = false;
			$response['errortext'] = curl_error($ch);

			$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Call failed. The error was %s: %s', $response['httpcode'], $responce['errortext'] ), 0);

			return (object)$response;
		} 
		
		$response ['success'] = true;
		$response['result'] = self::isJson($result)?json_decode($result):$result;

		$this->SendDebug(IPS_GetName($this->InstanceID), sprintf('Call returned: %s', $result), 0);
		
		return  (object)$response;
	}

    private function DownloadURL($Url, $File) {
        $fp = fopen($File, 'w+');
            
        if($fp === false){
            throw new Exception(sprintf('Failed to create file %s', $File));
        }
        
        $ch = curl_init($Url);
        
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_TIMEOUT, 45);

        $skipSSLCheck = $this->ReadPropertyBoolean('SkipSSLCheck');
	    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, !$skipSSLCheck?0:2);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, !$skipSSLCheck);
        
        curl_exec($ch);
        
        if(curl_errno($ch)) {
            throw new Exception(curl_error($ch));
        }
        
        curl_close($ch);
   }

    protected function isJson(string $Data) {
        json_decode($Data);
        return (json_last_error() == JSON_ERROR_NONE);
    }
}

trait Utility {
    protected function SecondsToNextHour() {
        $date = new DateTime('Now');
        $secSinceLastHour = $date->getTimestamp() % 3600; 
        return (3600 - $secSinceLastHour);
    }

    protected function GUID() {
        return sprintf('{%04X%04X-%04X-%04X-%04X-%04X%04X%04X}', mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(16384, 20479), mt_rand(32768, 49151), mt_rand(0, 65535), mt_rand(0, 65535), mt_rand(0, 65535));
    }

    protected function DayToString(int $AddDays=0) {
        $now = new DateTime('now');
        if($AddDays!=0) {
            if($AddDays>0) {
                $now->add(DateInterval::createFromDateString((string)$AddDays.' day'));
            } else {
                $now->sub(DateInterval::createFromDateString((string)-1*$AddDays.' day'));
            }
            
        }
        		               
        return $now->format('Y.m.d');
    }
}

trait Media {
    protected function CreateMediaByName(int $Parent, string $Name, int $Type, string $Ident){
		$id = @IPS_GetMediaIDByName($Name, $Parent);
		if($id === false) {
		  $id = IPS_CreateMedia($Type);
		  IPS_SetParent($id, $Parent);
		  IPS_SetName($id, $Name);
		  IPS_SetInfo($id, "This media object was created by the Entso-E library");
		  IPS_SetIdent($id, $Ident);
		}
		
        return $id;
	}
}


trait Profiles {
    protected function DeleteProfile($Name) {
        if(IPS_VariableProfileExists($Name))
            IPS_DeleteVariableProfile($Name);
    }

    protected function RegisterProfileString($Name, $Icon, $Prefix, $Suffix) {

        if (!IPS_VariableProfileExists($Name)) {
            IPS_CreateVariableProfile($Name, 3);
        } else {
            $profile = IPS_GetVariableProfile($Name);
            if ($profile['ProfileType'] != 3) {
                throw new Exception('Variable profile type (string) does not match for profile ' . $Name);
            }
        }

        IPS_SetVariableProfileIcon($Name, $Icon);
        IPS_SetVariableProfileText($Name, $Prefix, $Suffix);
    }

    protected function RegisterProfileStringEx($Name, $Icon, $Prefix, $Suffix, $Associations) {
        
        $this->RegisterProfileString($Name, $Icon, $Prefix, $Suffix);

        foreach ($Associations as $association) {
            IPS_SetVariableProfileAssociation($Name, $association[0], $association[1], $association[2], $association[3]);
        }
        
        // Remove assiciations that is not specified in $Associations
        $profileAssociations = IPS_GetVariableProfile($Name)['Associations'];
        foreach($profileAssociations as $profileAssociation) {
            $found = false;
            foreach($Associations as $association) {
                if($profileAssociation['Value']==$association[0]) {
                    $found = true;
                    break;
                }
            }

            if(!$found)
                IPS_SetVariableProfileAssociation($Name, $profileAssociation['Value'], '', '', -1);    
        }
    }

    protected function RegisterProfileBoolean($Name, $Icon, $Prefix, $Suffix) {

        if (!IPS_VariableProfileExists($Name)) {
            IPS_CreateVariableProfile($Name, 0);
        } else {
            $profile = IPS_GetVariableProfile($Name);
            if ($profile['ProfileType'] != 0) {
                throw new Exception('Variable profile type (boolean) does not match for profile ' . $Name);
            }
        }

        IPS_SetVariableProfileIcon($Name, $Icon);
        IPS_SetVariableProfileText($Name, $Prefix, $Suffix);
    }

    protected function RegisterProfileBooleanEx($Name, $Icon, $Prefix, $Suffix, $Associations) {
        
        $this->RegisterProfileBoolean($Name, $Icon, $Prefix, $Suffix);

        foreach ($Associations as $association) {
            IPS_SetVariableProfileAssociation($Name, $association[0], $association[1], $association[2], $association[3]);
        }
        
        // Remove assiciations that is not specified in $Associations
        $profileAssociations = IPS_GetVariableProfile($Name)['Associations'];
        foreach($profileAssociations as $profileAssociation) {
            $found = false;
            foreach($Associations as $association) {
                if($profileAssociation['Value']==$association[0]) {
                    $found = true;
                    break;
                }
            }

            if(!$found)
                IPS_SetVariableProfileAssociation($Name, $profileAssociation['Value'], '', '', -1);    
        }
    }
    
    protected function RegisterProfileInteger($Name, $Icon, $Prefix, $Suffix, $MinValue, $MaxValue, $StepSize) {
        if (!IPS_VariableProfileExists($Name)) {
            IPS_CreateVariableProfile($Name, 1);
        } else {
            $profile = IPS_GetVariableProfile($Name);
            if ($profile['ProfileType'] != 1) {
                throw new Exception('Variable profile type (integer) does not match for profile ' . $Name);
            }
        }

        IPS_SetVariableProfileIcon($Name, $Icon);
        IPS_SetVariableProfileText($Name, $Prefix, $Suffix);
        IPS_SetVariableProfileValues($Name, $MinValue, $MaxValue, $StepSize);
    }

    protected function RegisterProfileIntegerEx($Name, $Icon, $Prefix, $Suffix, $Associations) {
        
        if (count($Associations) === 0) {
            $MinValue = 0;
            $MaxValue = 0;
        } else {
            $MinValue = $Associations[0][0];
            $MaxValue = $Associations[count($Associations) - 1][0];
        }

        $this->RegisterProfileInteger($Name, $Icon, $Prefix, $Suffix, $MinValue, $MaxValue, 0);

        foreach ($Associations as $association) {
            IPS_SetVariableProfileAssociation($Name, $association[0], $association[1], $association[2], $association[3]);
        }
        
        // Remove assiciations that is not specified in $Associations
        $profileAssociations = IPS_GetVariableProfile($Name)['Associations'];
        foreach($profileAssociations as $profileAssociation) {
            $found = false;
            foreach($Associations as $association) {
                if($profileAssociation['Value']==$association[0]) {
                    $found = true;
                    break;
                }
            }

            if(!$found)
                IPS_SetVariableProfileAssociation($Name, $profileAssociation['Value'], '', '', -1);    
        }
    }

    protected function RegisterProfileFloat($Name, $Icon, $Prefix, $Suffix, $Digits=0) {

        if (!IPS_VariableProfileExists($Name)) {
            IPS_CreateVariableProfile($Name, 2);
        } else {
            $profile = IPS_GetVariableProfile($Name);
            if ($profile['ProfileType'] != 2) {
                throw new Exception('Variable profile type (float) does not match for profile ' . $Name);
            }
        }

        IPS_SetVariableProfileIcon($Name, $Icon);
        IPS_SetVariableProfileText($Name, $Prefix, $Suffix);
        IPS_SetVariableProfileDigits($Name, $Digits);
    }

    protected function CreateProfileAssosiationList($List) {
        $count = 0;
        foreach($List as $value) {
            $assosiations[] = [$count, $value,  '', -1];
            $count++;
        }

        return $assosiations;
    }

    protected function GetProfileAssosiationName($ProfileName, $Index) {
        $profile = IPS_GetVariableProfile($ProfileName);
    
        if($profile!==false) {
            foreach($profile['Associations'] as $association) {
                if($association['Value']==$Index)
                    return $association['Name'];
            }
        } 
    
        return false;
    
    }
}