<?php
/**
 * 中心服服务
 * Created by PhpStorm.
 * User: liuzhiming
 * Date: 16-8-19
 * Time: 下午3:56
 */

namespace Lib;
use Swoole;

class CenterServer  extends Swoole\Protocol\SOAServer
{
    const LOAD_TASKS = 0;//载入任务tasks进程
    const GET_TASKS = 1;//获取到期task进程
    const CLEAN_TASKS = 2;//清理过期task进程
    const EXEC_TASKS = 3;//执行task
    const MANAGER_TASKS = 4;//管理task状态

    function onWorkerStart($server, $worker_id)
    {
        if ($server->taskworker){
            if ($worker_id == (WORKER_NUM+self::LOAD_TASKS)){
                //准点载入任务
                $server->after((60-date("s"))*1000,function () use ($server){
                    Tasks::checkTasks();
                    $server->tick(60000, function () use ($server) {
                        Tasks::checkTasks();
                    });
                });
            }
            if ($worker_id == WORKER_NUM+self::GET_TASKS){
                $server->tick(1000, function () use ($server) {
                    $tasks = Tasks::getTasks();
                    $server->sendMessage(json_encode($tasks),(WORKER_NUM+self::EXEC_TASKS));
                });
            }
            if ($worker_id == WORKER_NUM+self::CLEAN_TASKS){
                //清理过期的服务器
                $server->tick(1000, function () use ($server) {
                    Robot::clean();
                });
            }
        }
    }
    public function onTask()
    {

    }
    public function onFinish(){}

    public function onPipeMessage($serv, $src_worker_id, $data)
    {
        $data = json_decode($data);
        if ($src_worker_id == WORKER_NUM+self::GET_TASKS){
            $ret = [];
            foreach ($data as $k=>$id)
            {
                $task = LoadTasks::getTasks()->get($id);
                $tmp["id"] = $id;
                $tmp["execute"] = $task["execute"];
                $tmp["agents"] = $task["agents"];
                $tmp["taskname"] = $task["taskname"];
                $tmp["runuser"] = $task["runuser"];
                $tmp["runid"] = $k;
                //任务标示
                LoadTasks::getTasks()->set($id,["runStatus"=>LoadTasks::RunStatusStart,"runTimeStart"=>microtime()]);
                //正在运行标示
                if ( Tasks::$table->exist($k)) Tasks::$table->set($k,["runStatus"=>LoadTasks::RunStatusStart,"runid"=>$k]);
                TermLog::log($tmp["runid"],$id,"任务开始",$tmp);
                $ret[$k] = [
                    "id"=>$id,
                    "ret"=>Robot::Run($tmp)
                ];
            }
            $serv->sendMessage(json_encode($ret),WORKER_NUM+self::MANAGER_TASKS);
        }else if ($src_worker_id == WORKER_NUM+self::MANAGER_TASKS){
            foreach ($data as $k=>$v){
                if ($v["ret"]){
                    $runStatus = LoadTasks::RunStatusToTaskSuccess;//发送成功
                    TermLog::log($k,$v["id"],"任务发送成功");
                }else{
                    $runStatus = LoadTasks::RunStatusToTaskFailed;//发送失败
                    TermLog::log($k,$v["id"],"任务发送失败");
                    Report::taskSendFailed($v["id"],$k);//报警
                }
                LoadTasks::getTasks()->set($v["id"],["runStatus"=>$runStatus,"runUpdateTime"=>microtime()]);
                if ( Tasks::$table->exist($k)) Tasks::$table->set($k,["runStatus"=>$runStatus]);
            }
        }
    }

    public function call($request, $header)
    {
        //初始化日志
        Flog::startLog($request['call']);
        Flog::log("call:".$request['call'].",params:".json_encode($request['params']));
        $ret =  parent::call($request, $header); // TODO: Change the autogenerated stub
        Flog::log($ret);
        Flog::endLog();
        Flog::flush();
        return $ret;
    }
}