<?php
/* How you add a job */
//SampleJob::create($Params, $QueueName);
//SampleJob::create_at($Timestamp, $Params, $QueueName);

    function exception_error_handler($severity, $message, $file, $line)
    {
        if (!(error_reporting() & $severity)) {
            // This error code is not included in error_reporting
            //return;
        }
        throw new Exception($file.':'.$line.' -> '.$message);
    }

    class ifx_Worker extends ifx_Model
    {
        const ifx_worker_id = 'worker_id';

        const WORKER_STATE_READY = 1;
        const WORKER_STATE_BUSY = 2;
        const WORKER_STATE_SLEEPING = 3;
        const WORKER_STATE_INACTIVE = 0;

        public function enable()
        {
            if ($this->enabled == true) {
                return true;
            }

            $time = time();

            $this->db->set('enabled', true)
                    ->set('status', self::WORKER_STATE_READY)
                    ->set('start_time', $time)
                    ->set('last_updated', $time)
                    ->set('process_id', null)
                    ->where($this->_id(), $this->id())
                    ->where('status', self::WORKER_STATE_INACTIVE)
                    ->where('enabled', false)
                    ->update($this->_table());

            if ($this->db->affected_rows() == 1) {
                $this->enabled = true;
                $this->status = self::WORKER_STATE_READY;
                $this->start_time = $time;
                $this->process_id = null;

                ifx_Scheduler_History::create('WORKER', $this->id(), $this->name. ' worker enabled', $this->_data);

                return true;
            } else {
                return false;
            }
        }

        public function stop()
        {
            if ($this->enabled == false) {
                return true;
            }

            $time = time();

            $this->db->set('enabled', false)
                    ->set('status', self::WORKER_STATE_INACTIVE)
                    ->set('last_updated', $time)
                    ->where($this->_id(), $this->id())
                    ->where('enabled', 1)
                    ->update($this->_table());

            if ($this->db->affected_rows() == 1) {
                ifx_Scheduler_History::create('WORKER', $this->id(), $this->name. ' worker stop requested', $this->_data);

                $this->enabled = false;
                $this->status = self::WORKER_STATE_INACTIVE;
                $this->process_id = null;
                $this->memory_usage = null;
                $this->last_updated = $time;

                return true;
            } else {
                return false;
            }
        }

        private function stop_process()
        {
            if ($this->enabled == false) {
                return true;
            }

            $time = time();

            $this->db->set('last_updated', $time)
                    ->set('process_id', null)
                    ->set('status', self::WORKER_STATE_INACTIVE)
                    ->set('memory_usage', 0)
                    ->where($this->_id(), $this->id())
                    ->where('enabled', false)
                    ->update($this->_table());

            if ($this->db->affected_rows() == 1) {
                $this->enabled = false;
                $this->status = self::WORKER_STATE_INACTIVE;
                $this->memory_usage = null;
                $this->last_updated = $time;

                ifx_Scheduler_History::create('WORKER', $this->id(), $this->name. ' worker process shutdown complete.', $this->_data);

                $this->process_id = null;

                return true;
            } else {
                return false;
            }
        }

        public function recover_lost_jobs()
        {
            $Job = new ifx_Job();

            $Job->db->set('status', ifx_Job::JOB_STATE_NEW)
                    ->set('worker_id', null)
                    ->set('retry_count', 'retry_count+1', false)
                    ->set('run_after', time().' + (retry_count * '.$this->job_retry_time.')', false)
                    ->where_in('status', [ifx_Job::JOB_STATE_QUEUED, ifx_Job::JOB_STATE_RUNNING])
                    ->where('worker_id', $this->id())
                    ->update($Job->_table());
        }

        public function run()
        {
            if (!$this->is_loaded() || !$this->enabled) {
                return;
            }

            //Stop memory leaking
            $this->db->save_queries = false;
            $this->db->cache_on = false;

            if (!empty($this->process_id)) {
                die('Process already running');
            } else {
                $this->process_id = substr(md5(time()), 0, 6);
                //$this->db->where('status', SELF::WORKER_STATE_INACTIVE)
                $this->save();

                ifx_Scheduler_History::create('WORKER', $this->id(), $this->name. ' worker processing started with id:'.$this->process_id, $this->_data);
            }
            set_exception_handler(null);
            set_error_handler('exception_error_handler');

            while (true) {
                //$this->refresh();

                //If the worker has been disabled, update and break out
                /*    $this->stop_process();
                    //ifx_Scheduler_History::create('WORKER', $this->id(), $this->name. ' worker process stop completed', $this->_data);
                    die('Worker not running');
                    break;
                }*/

                $time = time();
                $memory_usage = memory_get_usage();
                $memory_usage_max = memory_get_peak_usage();

                //If the worker looks likes its already running, abort
                $query = $this->db->set('status', self::WORKER_STATE_BUSY)
                        ->set('last_updated', $time)
                        ->set('memory_usage', $memory_usage)
                        ->set('memory_usage_max', $memory_usage_max)
                        ->where($this->_id(), $this->id())
                        ->where('enabled', true)
                        ->where('status', self::WORKER_STATE_READY)
                        ->where('process_id', $this->process_id)
                        ->update($this->_table());

                if ($this->db->affected_rows() !== 1) {
                    $this->stop_process();
                    //ifx_Scheduler_History::create('WORKER', $this->id(), $this->name. ' worker process stopped.', $this->_data);
                    die('Worker already running');
                    break;
                } else {
                    $this->status = self::WORKER_STATE_BUSY;
                    $this->last_updated = $time;
                    $this->memory_usage = $memory_usage;
                    $this->memory_usage_max = $memory_usage_max;
                }

                //Fetch next job
                $Job = ifx_Job::next($this);

                //Keep looping if no jobs available
                if ($Job !== false) {
                    ifx_Scheduler_History::create('WORKER', $this->id(), $this->name. ' worker processing job:'.$Job->id, $Job->_data);

                    try {
                        $jobstarttime = time();

                        if ($Job->begin()) {
                            ifx_Scheduler_History::create('WORKER', $this->id(), $this->name. ' worker completed job:'.$Job->id, $Job->_data);
                            $Job->complete();
                        } else {
                            ifx_Scheduler_History::create('WORKER', $this->id(), $this->name. ' worker processing job failed:'.$Job->id, $Job->_data);
                            $Job->failed();
                        }
                    } catch (Throwable $Thrown) {
                        switch (gettype($Thrown)) {
                            default:
                                $Error['message'] = gettype($Thrown).': '.$Thrown->getMessage();
                                $Error['file'] = $Thrown->getFile().':'.$Thrown->getLine();
                                $Error['trace'] = $Thrown->getTraceAsString();
                        }
                        //Failed, record the error

                        $Return = array_merge($Error, $Job->_data);

                        ifx_Scheduler_History::create('WORKER', $this->id(), $this->name. ' worker processing job failed:'.$Job->id, $Return);
                        $Job->failed();
                    }
                    gc_collect_cycles();

                    $jobtotaltime = $jobstarttime - time();

                    if ($jobtotaltime > $this->wait_between_jobs_starting) {
                        $waittime = $this->wait_between_jobs_starting - $jobtotaltime;

                        sleep($waittime);
                    }
                } else {
                    //Fixup any lost jobs
                    $this->recover_lost_jobs();
                    $this->db->set('status', self::WORKER_STATE_SLEEPING)
                        ->where($this->_id(), $this->id())
                        ->where('process_id', $this->process_id)
                        ->update($this->_table());
                    sleep($this->wait_no_job_available);
                }

                $this->db->set('status', self::WORKER_STATE_READY)
                        ->where($this->_id(), $this->id())
                        ->where('process_id', $this->process_id)
                        ->update($this->_table());

                if ($this->db->affected_rows() == 1) {
                    $this->status = self::WORKER_STATE_READY;
                }
            }
        }
    }
