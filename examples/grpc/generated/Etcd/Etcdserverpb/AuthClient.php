<?php
// GENERATED CODE -- DO NOT EDIT!

namespace Etcdserverpb;

/**
 */
class AuthClient extends \Swoole\Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Swoole\Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts = []) {
        parent::__construct($hostname, $opts);
    }

    /**
     * AuthEnable enables authentication.
     * @param \Etcdserverpb\AuthEnableRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Etcdserverpb\AuthEnableResponse[]|\Swoole\Grpc\StringifyAble[]
     */
    public function AuthEnable(\Etcdserverpb\AuthEnableRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/etcdserverpb.Auth/AuthEnable',
        $argument,
        ['\Etcdserverpb\AuthEnableResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * AuthDisable disables authentication.
     * @param \Etcdserverpb\AuthDisableRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Etcdserverpb\AuthDisableResponse[]|\Swoole\Grpc\StringifyAble[]
     */
    public function AuthDisable(\Etcdserverpb\AuthDisableRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/etcdserverpb.Auth/AuthDisable',
        $argument,
        ['\Etcdserverpb\AuthDisableResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Authenticate processes an authenticate request.
     * @param \Etcdserverpb\AuthenticateRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Etcdserverpb\AuthenticateResponse[]|\Swoole\Grpc\StringifyAble[]
     */
    public function Authenticate(\Etcdserverpb\AuthenticateRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/etcdserverpb.Auth/Authenticate',
        $argument,
        ['\Etcdserverpb\AuthenticateResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * UserAdd adds a new user.
     * @param \Etcdserverpb\AuthUserAddRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Etcdserverpb\AuthUserAddResponse[]|\Swoole\Grpc\StringifyAble[]
     */
    public function UserAdd(\Etcdserverpb\AuthUserAddRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/etcdserverpb.Auth/UserAdd',
        $argument,
        ['\Etcdserverpb\AuthUserAddResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * UserGet gets detailed user information.
     * @param \Etcdserverpb\AuthUserGetRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Etcdserverpb\AuthUserGetResponse[]|\Swoole\Grpc\StringifyAble[]
     */
    public function UserGet(\Etcdserverpb\AuthUserGetRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/etcdserverpb.Auth/UserGet',
        $argument,
        ['\Etcdserverpb\AuthUserGetResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * UserList gets a list of all users.
     * @param \Etcdserverpb\AuthUserListRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Etcdserverpb\AuthUserListResponse[]|\Swoole\Grpc\StringifyAble[]
     */
    public function UserList(\Etcdserverpb\AuthUserListRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/etcdserverpb.Auth/UserList',
        $argument,
        ['\Etcdserverpb\AuthUserListResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * UserDelete deletes a specified user.
     * @param \Etcdserverpb\AuthUserDeleteRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Etcdserverpb\AuthUserDeleteResponse[]|\Swoole\Grpc\StringifyAble[]
     */
    public function UserDelete(\Etcdserverpb\AuthUserDeleteRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/etcdserverpb.Auth/UserDelete',
        $argument,
        ['\Etcdserverpb\AuthUserDeleteResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * UserChangePassword changes the password of a specified user.
     * @param \Etcdserverpb\AuthUserChangePasswordRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Etcdserverpb\AuthUserChangePasswordResponse[]|\Swoole\Grpc\StringifyAble[]
     */
    public function UserChangePassword(\Etcdserverpb\AuthUserChangePasswordRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/etcdserverpb.Auth/UserChangePassword',
        $argument,
        ['\Etcdserverpb\AuthUserChangePasswordResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * UserGrant grants a role to a specified user.
     * @param \Etcdserverpb\AuthUserGrantRoleRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Etcdserverpb\AuthUserGrantRoleResponse[]|\Swoole\Grpc\StringifyAble[]
     */
    public function UserGrantRole(\Etcdserverpb\AuthUserGrantRoleRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/etcdserverpb.Auth/UserGrantRole',
        $argument,
        ['\Etcdserverpb\AuthUserGrantRoleResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * UserRevokeRole revokes a role of specified user.
     * @param \Etcdserverpb\AuthUserRevokeRoleRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Etcdserverpb\AuthUserRevokeRoleResponse[]|\Swoole\Grpc\StringifyAble[]
     */
    public function UserRevokeRole(\Etcdserverpb\AuthUserRevokeRoleRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/etcdserverpb.Auth/UserRevokeRole',
        $argument,
        ['\Etcdserverpb\AuthUserRevokeRoleResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * RoleAdd adds a new role.
     * @param \Etcdserverpb\AuthRoleAddRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Etcdserverpb\AuthRoleAddResponse[]|\Swoole\Grpc\StringifyAble[]
     */
    public function RoleAdd(\Etcdserverpb\AuthRoleAddRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/etcdserverpb.Auth/RoleAdd',
        $argument,
        ['\Etcdserverpb\AuthRoleAddResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * RoleGet gets detailed role information.
     * @param \Etcdserverpb\AuthRoleGetRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Etcdserverpb\AuthRoleGetResponse[]|\Swoole\Grpc\StringifyAble[]
     */
    public function RoleGet(\Etcdserverpb\AuthRoleGetRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/etcdserverpb.Auth/RoleGet',
        $argument,
        ['\Etcdserverpb\AuthRoleGetResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * RoleList gets lists of all roles.
     * @param \Etcdserverpb\AuthRoleListRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Etcdserverpb\AuthRoleListResponse[]|\Swoole\Grpc\StringifyAble[]
     */
    public function RoleList(\Etcdserverpb\AuthRoleListRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/etcdserverpb.Auth/RoleList',
        $argument,
        ['\Etcdserverpb\AuthRoleListResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * RoleDelete deletes a specified role.
     * @param \Etcdserverpb\AuthRoleDeleteRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Etcdserverpb\AuthRoleDeleteResponse[]|\Swoole\Grpc\StringifyAble[]
     */
    public function RoleDelete(\Etcdserverpb\AuthRoleDeleteRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/etcdserverpb.Auth/RoleDelete',
        $argument,
        ['\Etcdserverpb\AuthRoleDeleteResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * RoleGrantPermission grants a permission of a specified key or range to a specified role.
     * @param \Etcdserverpb\AuthRoleGrantPermissionRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Etcdserverpb\AuthRoleGrantPermissionResponse[]|\Swoole\Grpc\StringifyAble[]
     */
    public function RoleGrantPermission(\Etcdserverpb\AuthRoleGrantPermissionRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/etcdserverpb.Auth/RoleGrantPermission',
        $argument,
        ['\Etcdserverpb\AuthRoleGrantPermissionResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * RoleRevokePermission revokes a key or range permission of a specified role.
     * @param \Etcdserverpb\AuthRoleRevokePermissionRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Etcdserverpb\AuthRoleRevokePermissionResponse[]|\Swoole\Grpc\StringifyAble[]
     */
    public function RoleRevokePermission(\Etcdserverpb\AuthRoleRevokePermissionRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/etcdserverpb.Auth/RoleRevokePermission',
        $argument,
        ['\Etcdserverpb\AuthRoleRevokePermissionResponse', 'decode'],
        $metadata, $options);
    }

}
