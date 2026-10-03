<?php

require_once __DIR__ . '/ApiRequestException.php';
require_once __DIR__ . '/../Authorization/RoleModel.php';
require_once __DIR__ . '/../Authorization/FrontendCapabilityPolicy.php';
require_once __DIR__ . '/../Security/UsernamePolicy.php';
require_once __DIR__ . '/../Security/PasswordPolicy.php';
require_once __DIR__ . '/../Security/UserProfilePolicy.php';

final class FrontendUserRequestValidator
{
    private const ACTIONS=['auth.frontendUsers.list','auth.frontendUsers.create','auth.frontendUsers.update','auth.frontendUsers.enable','auth.frontendUsers.disable','auth.frontendUsers.delete','auth.frontendUsers.changePassword','auth.frontendUsers.assignRole'];
    public function validate(array $request):array
    {
        $action=$request['action']??null;if(!is_string($action)||!in_array($action,self::ACTIONS,true))$this->invalid();
        if($action==='auth.frontendUsers.list'){$this->unknown($request,['action']);return['action'=>$action];}
        $allowed=match($action){'auth.frontendUsers.create'=>['action','name','username','mobile','email','password','passwordConfirmation','enabled','role','frontendRole'],'auth.frontendUsers.update'=>['action','username','name','newUsername','mobile','email'],'auth.frontendUsers.changePassword'=>['action','username','newPassword','passwordConfirmation'],'auth.frontendUsers.assignRole'=>['action','username','frontendAccess','frontendRole'],default=>['action','username']};
        $this->unknown($request,$allowed);$details=[];try{$username=UsernamePolicy::normalize($request['username']??null);}catch(InvalidArgumentException $e){$username='';$details[]=['path'=>'username','message'=>$e->getMessage()];}$result=['action'=>$action,'username'=>$username];
        if($action==='auth.frontendUsers.create'){$this->profile($request,$result,$details);$this->password($request['password']??null,'password',$result,$details);$this->confirm($request['passwordConfirmation']??null,$result['password']??null,'passwordConfirmation',$details);$enabled=$request['enabled']??true;if(!is_bool($enabled))$details[]=['path'=>'enabled','message'=>'Enabled status must be boolean.'];else$result['enabled']=$enabled;if(array_key_exists('role',$request)&&array_key_exists('frontendRole',$request))$details[]=['path'=>'role','message'=>'Use one role property.'];$result['role']=array_key_exists('role',$request)?$this->createRole($request['role'],$details):$this->role($request['frontendRole']??null,$details);}
        elseif($action==='auth.frontendUsers.update'){$this->profile($request,$result,$details);try{$result['newUsername']=UsernamePolicy::normalize($request['newUsername']??null);}catch(InvalidArgumentException $e){$details[]=['path'=>'newUsername','message'=>$e->getMessage()];}}
        elseif($action==='auth.frontendUsers.changePassword'){$this->password($request['newPassword']??null,'newPassword',$result,$details);$this->confirm($request['passwordConfirmation']??null,$result['newPassword']??null,'passwordConfirmation',$details);}
        elseif($action==='auth.frontendUsers.assignRole'){$access=$request['frontendAccess']??true;if(!is_bool($access))$details[]=['path'=>'frontendAccess','message'=>'Frontend access must be boolean.'];else$result['frontendAccess']=$access;$result['frontendRole']=$this->role($request['frontendRole']??null,$details);if($access===false&&$result['frontendRole']!==null)$details[]=['path'=>'frontendRole','message'=>'Frontend role requires frontend access.'];}
        if($details!==[])$this->invalid($details);return$result;
    }
    private function role($role,array &$details):?string{if(!in_array($role,[null,RoleModel::APPLICATION_ADMINISTRATOR],true))$details[]=['path'=>'frontendRole','message'=>'Invalid frontend role.'];return$role;}
    private function createRole($role,array &$details):?string{if(!in_array($role,FrontendCapabilityPolicy::assignableRoles(),true))$details[]=['path'=>'role','message'=>'Role is not supported by the frontend application.'];return is_string($role)?$role:null;}
    private function profile(array$request,array&$result,array&$details):void{foreach(['name','mobile','email']as$field){try{$result[$field]=match($field){'name'=>UserProfilePolicy::name($request[$field]??null),'mobile'=>UserProfilePolicy::mobile($request[$field]??null),default=>UserProfilePolicy::email($request[$field]??null)};}catch(InvalidArgumentException$e){$details[]=['path'=>$field,'message'=>$e->getMessage()];}}}
    private function password($value,string$field,array&$result,array&$details):void{try{$result[$field]=PasswordPolicy::validate($value);}catch(InvalidArgumentException$e){$details[]=['path'=>$field,'message'=>$e->getMessage()];}}
    private function confirm($value,?string$password,string$field,array&$details):void{if(!is_string($value))$details[]=['path'=>$field,'message'=>'Password confirmation is required.'];elseif($password!==null&&!hash_equals($password,$value))$details[]=['path'=>$field,'message'=>'Password confirmation does not match.'];}
    private function unknown(array$request,array$allowed):void{$details=[];foreach(array_keys($request)as$key)if(!in_array($key,$allowed,true))$details[]=['path'=>$key,'message'=>'Unknown property.'];if($details!==[])$this->invalid($details);}
    private function invalid(array$details=[]):never{throw new ApiRequestException('Invalid frontend user request.','INVALID_FRONTEND_USER_REQUEST',$details);}
}
