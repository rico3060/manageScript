<?php
define('DS', DIRECTORY_SEPARATOR);
define('ROOT_PATH', __DIR__ . DS);//定义根目录路径
define('CORE_PATH', ROOT_PATH . 'core' . DS);//定义类文件存储路径
define('LOG_PATH', ROOT_PATH . 'log' . DS);//定义日志路径
define('CONFIG_PATH', ROOT_PATH . 'config' .DS);//定义配置文件路径
include_once CORE_PATH .'start'. EXT;

use Core\Cen\Query;

class Master
{
    private $server = null;

    private $params = [];
    //记录脚本信息, 用于kill
    private $scripts = [];
    //记录脚本启动时间
    private $script_start_time = [];
    //php脚本解析位置
    private $php;
    //脚本位置
    private $script_dir;
    //地址
    private $host;
    //端口号
    private $port;
    //守护进程
    private $daeme;
    //Master_id
    public $master_id;
    private $status = false;
    //信息
    private $message = '[ %s ] : %s';

    public function __construct($host,$port)
    {
        
        $this->host = $host;
        $this->port = $port;
        $this->script_dir =  ROOT_PATH . 'index.php';
        $this->php  = getenv('_');
        $this->server =  $this->server ?  :
        new swoole_server($this->host, $this->port,SWOOLE_BASE,SWOOLE_SOCK_TCP);
    }
    
    /**
     * @description:设置参数
     * @author wuyanwen(2017年4月17日)
     */
    protected function set()
    {
        $this->server->set($this->params);
    }
    
    
    /**
     * @description:接收消息
     * @author wuyanwen(2017年4月17日)
     */
    protected function receive()
    {

        $server = $this->server;
        $this->server->on('receive', function ($server, $fd, $from_id, $data){
            //解析脚本操作
            try {
            list($option,$script) = explode(',', $data);
            //执行操作
            $msg = call_user_func_array([__CLASS__,$option.'Script'], [$script]);
            //发送执行结果
            $server->send($fd,json_encode($msg));
            //关闭连接
            $this->server->close($fd);
            //脚本信号接收
            if (!$this->status) {
                $this->dealSignal();
            }
            } catch (\Exception $e) {
                echo $e->getMessage() . PHP_EOL;
                echo $e->getTraceAsString();
            }
            
        });
    }

    /**
     * @description:信号处理
     * @author wuyanwen(2017年4月26日)
     */
    protected function dealSignal()
    {$this->status = true;
        swoole_process::signal(SIGCHLD, function($signo)  {
            //必须为false，非阻塞模式
            while($result =  swoole_process::wait(false)) {
                $script = array_search($result['pid'], $this->scripts);
                if (isset($this->scripts[$script]) && $this->scripts[$script]) {
                    unset($this->scripts[$script]);
                    unset($this->script_start_time[$script]);
                    $this->updateStatus($script,2);
                    echo $this->sendMessage($script .'脚本退出');
                   // echo sprintf($this->message,date('Y-m-d H:i:s'), $script . '脚本退出');
                }
            }
        });
    }
    /**
     * @description:启动脚本
     * @author wuyanwen(2017年4月19日)
     */
    public function startScript($script)
    {
        if (isset($this->scripts[$script]) && $this->scripts[$script]) {
            echo $this->sendMessage( $script . '脚本正在运行中~');
            //echo sprintf($this->message,date('Y-m-d H:i:s'),);
        } else {
            //启动脚本
            try {
                $process = new swoole_process(function($process) use ($script){
                    $process->name(sprintf('php worker %s', $script));
                    $process->exec($this->php,[$this->script_dir,$script]);
                    },true);
            } catch (\Exception $e) {
                throw new \Exception($e->getMessage());
            }
            $pid = $process->start();
            //脚本
            if (!$pid) {
               $error_code = swoole_errno();
                $message = sprintf('进程错误码 : %d 进程错误信息: %s ',$error_code,swoole_strerror($error_code));
                $message .= $script . '脚本启动失败';
                echo $this->sendMessage($message);
                //echo sprintf($this->message,date('Y-m-d H:i:s'), $message);
            }
            //存储脚本信息
            $this->scripts[$script] = $pid;
            //存储脚本运行的开始时间
            $this->script_start_time[$script] = time();
            echo $this->sendMessage($script .'脚本启动成功~');
            $this->updateStatus($script);
            //echo sprintf($this->message,date('Y-m-d H:i:s'), $script . '脚本启动成功~');
        }
        
    }

    /**
     * @description:停止脚本
     * @author wuyanwen(2017年4月19日)
     */
    public function stopScript($script)
    {
        if (isset($this->scripts[$script]) && $this->scripts[$script]) {
            $result = swoole_process::kill($this->scripts[$script]);
            if ($result) {
                unset($this->scripts[$script]);
                unset($this->script_start_time[$script]);
                $this->updateStatus($script, 2);
                echo $this->sendMessage($script .'脚本已经停止~');
               //echo sprintf($this->message,date('Y-m-d H:i:s'), $script . '脚本已经停止~');
                //return $this->msg(10001,'脚本已经停止~');
            } else {
                echo $this->sendMessage($script .'脚本停止操作失败~');
              // echo sprintf($this->message,date('Y-m-d H:i:s'), $script . '脚本停止操作失败~');
                //return $this->msg(10001,'脚本停止操作失败~');
            }
        } else {
            // echo sprintf($this->message,date('Y-m-d H:i:s'), $script . '脚本未启动~');
             echo $this->sendMessage($script . '脚本未启动~');
            //return $this->msg(10001,'脚本未启动~');
        }
    }

    /**
     * @description:查看脚本状态
     * @author wuyanwen(2017年4月19日)
     */
    public function statusScript($script)
    {
        if (isset($this->scripts[$script]) && $this->scripts[$script]) {
            $msg = $this->countTime($this->script_start_time[$script], time());
            echo $this->sendMessage($script . '脚本正在运行中~已经运行'.$msg);
           //echo sprintf($this->message,date('Y-m-d H:i:s'), $script . '脚本正在运行中~已经运行'.$msg);
          // return $this->msg(10001,'脚本正在运行中~已经运行'.$msg);
        } else {
            echo $this->sendMessage($script . '脚本未启动~');
          // echo sprintf($this->message,date('Y-m-d H:i:s'), $script . '脚本未启动~');
           //return $this->msg(10001,'脚本未启动~');
        }
    }
    /**
     * @description:脚本信息
     * @author wuyanwen(2017年4月19日)
     * @param unknown $code
     * @param unknown $msg
     */
    protected function msg($code = 10000,$msg = '')
    {
        return ['code' => $code, 'msg' => $msg];
    }
    
    /**
     * @description:计算时间
     * @author wuyanwen(2017年4月12日)
     */
    protected function countTime($start_time,$end_time)
    {
         
        $day_time = 24 * 3600;

        $time = $end_time - $start_time;

        //运行小于一天
        if ($time < $day_time) return gmstrftime('%H时%M分%S秒', $time);

        //运行大于一天
        $days = floor($time / $day_time);

        $time_str = gmstrftime('%H时%M分%S秒', $time % $day_time);

        return $days.'天'.$time_str;
    }
    
    /**
     * @description:更新运行 1:运行脚本  2:结束脚本
     * @author wuyanwen(2017年7月17日)
     * @param unknown $script
     * @param number $update
     */
    public function updateStatus($script, $update = 1)
    {
        $query = new Query();

        $query->table('scripts');
        if ($update == 1) {
            $query->start_time = time();
            $query->status     = 2;
            $query->where('script',$script)->update();
        } else {
            $query->end_time = time();
            $query->status   = 1;
            $query->where('script',$script)->update();
        }
    }
    
    /**
     * @description:发送消息
     * @author wuyanwen(2017年7月17日)
     * @param unknown $msg
     * @return string
     */
    private function sendMessage($msg)
    {
        return sprintf($this->message,date('Y-m-d H:i:s'), $msg . PHP_EOL);
    }
    /**
     * @description:设置属性
     * @author wuyanwen(2017年4月17日)
     * @param unknown $key
     * @param unknown $value
     */
    public function __set($key,$value)
    {
        $this->params[$key] = $value;
    }
     
    /**
     * @description:开启服务
     * @author wuyanwen(2017年4月14日)
     */
    public function start()
    {
        //设置参数
        if (!empty($this->params)) 
            $this->set();
        //接收消息
        $this->receive();
        //启动server
        $this->server->start();        
    }
      
}
$master_config = \Core\Cen\Config::get('config')['master'];
$host = $master_config['host'];
$port = $master_config['port'];
$server = new Master($host,$port);
$server->daemonize = $master_config['daeme'];
if ($master_config['pid_file']) {
    $server->pid_file = $master_config['pid_file'];
}

$server->log_file = $master_config['log_path'];
$server->log_level = $master_config['log_level'];
\Core\Cen\Log::write(\Core\Cen\Log::INFO, '@守护进程已经启动');
$server->start();