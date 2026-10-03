<?php

require_once __DIR__ . '/ApiRequestException.php';
require_once __DIR__ . '/../Authorization/RoleModel.php';
require_once __DIR__ . '/../Security/UsernamePolicy.php';
require_once __DIR__ . '/../Security/PasswordPolicy.php';
require_once __DIR__ . '/../Security/UserProfilePolicy.php';

final class UserManagementRequestValidator
{
    private const ACTIONS = ['auth.users.list','auth.users.create','auth.users.update','auth.users.enable','auth.users.disable','auth.users.delete','auth.users.changePassword','auth.users.assignAuthorization'];

    public function validate(array $request): array
    {
        $action = $request['action'] ?? null;
        if (!is_string($action) || !in_array($action, self::ACTIONS, true)) $this->invalid();
        if ($action === 'auth.users.list') { $this->unknown($request, ['action']); return ['action' => $action]; }

        $allowed = match ($action) {
            'auth.users.create' => ['action','name','username','mobile','email','password','passwordConfirmation','enabled','role'],
            'auth.users.update' => ['action','username','name','newUsername','mobile','email'],
            'auth.users.changePassword' => ['action','username','newPassword','passwordConfirmation'],
            'auth.users.assignAuthorization' => ['action','username','role'],
            default => ['action','username'],
        };
        $this->unknown($request, $allowed);
        $details = [];
        try { $username = UsernamePolicy::normalize($request['username'] ?? null); }
        catch (InvalidArgumentException $exception) { $username=''; $details[]=['path'=>'username','message'=>$exception->getMessage()]; }
        $validated=['action'=>$action,'username'=>$username];

        if ($action === 'auth.users.create') {
            $validated += $this->profile($request, $details);
            $this->password($request['password'] ?? null, 'password', $validated, $details);
            $this->confirmation($request['passwordConfirmation'] ?? null, $validated['password'] ?? null, 'passwordConfirmation', $details);
            $validated += $this->rolePreset($request['role'] ?? null, $details);
            $enabled=$request['enabled']??true;
            if(!is_bool($enabled))$details[]=['path'=>'enabled','message'=>'Enabled status must be boolean.'];
            else {
                $validated['enabled']=$enabled;
                if($enabled && $validated['backendRole']===null && $validated['frontendAccess']===false) {
                    $details[]=['path'=>'backendRole','message'=>'An enabled user requires backend or frontend access.'];
                }
            }
        } elseif ($action === 'auth.users.update') {
            $validated += $this->profile($request, $details);
            try { $validated['newUsername']=UsernamePolicy::normalize($request['newUsername']??null); }
            catch(InvalidArgumentException $exception){$details[]=['path'=>'newUsername','message'=>$exception->getMessage()];}
        } elseif ($action === 'auth.users.changePassword') {
            $this->password($request['newPassword'] ?? null, 'newPassword', $validated, $details);
            $this->confirmation($request['passwordConfirmation'] ?? null, $validated['newPassword'] ?? null, 'passwordConfirmation', $details);
        } elseif ($action === 'auth.users.assignAuthorization') {
            $validated += $this->rolePreset($request['role'] ?? null, $details);
        }
        if($details!==[])$this->invalid($details);
        return $validated;
    }

    private function profile(array $request, array &$details): array
    {
        $profile = [];
        foreach (['name' => 'name', 'mobile' => 'mobile', 'email' => 'email'] as $field => $method) {
            try { $profile[$field] = UserProfilePolicy::$method($request[$field] ?? null); }
            catch (InvalidArgumentException $exception) { $details[] = ['path' => $field, 'message' => $exception->getMessage()]; }
        }
        return $profile;
    }

    private function rolePreset($role, array &$details): array
    {
        $presets = [
            RoleModel::SYSTEM_ADMINISTRATOR => ['backendRole' => RoleModel::SYSTEM_ADMINISTRATOR, 'frontendAccess' => true, 'frontendRole' => RoleModel::APPLICATION_ADMINISTRATOR],
            RoleModel::APPLICATION_ADMINISTRATOR => ['backendRole' => null, 'frontendAccess' => true, 'frontendRole' => RoleModel::APPLICATION_ADMINISTRATOR],
            RoleModel::DATA_OPERATOR => ['backendRole' => RoleModel::DATA_OPERATOR, 'frontendAccess' => true, 'frontendRole' => null],
            RoleModel::READ_ONLY => ['backendRole' => RoleModel::READ_ONLY, 'frontendAccess' => true, 'frontendRole' => null],
        ];
        if (!is_string($role) || !array_key_exists($role, $presets)) {
            $details[] = ['path' => 'role', 'message' => 'Invalid user role.'];
            return ['backendRole' => null, 'frontendAccess' => false, 'frontendRole' => null];
        }
        return $presets[$role];
    }
    private function password($value,string $field,array &$validated,array &$details):void{try{$validated[$field]=PasswordPolicy::validate($value);}catch(InvalidArgumentException $exception){$details[]=['path'=>$field,'message'=>$exception->getMessage()];}}
    private function confirmation($value,?string $password,string $field,array &$details):void{if(!is_string($value))$details[]=['path'=>$field,'message'=>'Password confirmation is required.'];elseif($password!==null&&!hash_equals($password,$value))$details[]=['path'=>$field,'message'=>'Password confirmation does not match.'];}
    private function unknown(array $request,array $allowed):void{$details=[];foreach(array_keys($request)as$key)if(!in_array($key,$allowed,true))$details[]=['path'=>$key,'message'=>'Unknown property.'];if($details!==[])$this->invalid($details);}
    private function invalid(array $details=[]):never{throw new ApiRequestException('Invalid user request.','INVALID_USER_REQUEST',$details);}
}
