<?php

function DefaultResponse($code, $success = false, $msg = '加载错误', $data = null)
{
    return ['code' => $code, 'success' => $success, 'msg' => $msg, 'data' => $data];
}

function DefaultResponseList($code, $count = 0, $msg = '加载错误', $obj = null)
{
    return ['code' => $code, 'count' => $count, 'msg' => $msg, 'data' => $obj];
}

function tmNow()
{
    return date('Y-m-d H:i:s');
}

function tmRoleLabels()
{
    return [
        'leader'      => '班组长',
        'deputy'      => '组长',
        'safety'      => '安全员',
        'part_safety' => '兼职安全员',
        'member'      => '普通成员',
    ];
}
