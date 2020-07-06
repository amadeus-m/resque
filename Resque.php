<?php

namespace ResqueBundle\Resque;

use Psr\Log\NullLogger;

/**
 * Class Resque
 * @package ResqueBundle\Resque
 */
class Resque implements EnqueueInterface
{
    /**
     * @var array
     */
    private $kernelOptions;

    /**
     * @var array
     */
    private $redisConfiguration;

    /**
     * @var array
     */
    private $globalRetryStrategy = [];

    /**
     * @var array
     */
    private $jobRetryStrategy = [];

    /**
     * Resque constructor.
     * @param array $kernelOptions
     */
    public function __construct(array $kernelOptions)
    {
        $this->kernelOptions = $kernelOptions;
    }

    /**
     * @param $prefix
     */
    public function setPrefix($prefix)
    {
        \Resque_Redis::prefix($prefix);
    }

    /**
     * @param $strategy
     */
    public function setGlobalRetryStrategy($strategy)
    {
        $this->globalRetryStrategy = $strategy;
    }

    /**
     * @param $strategy
     */
    public function setJobRetryStrategy($strategy)
    {
        $this->jobRetryStrategy = $strategy;
    }

    /**
     * @return array
     */
    public function getRedisConfiguration()
    {
        return $this->redisConfiguration;
    }

    /**
     * @param $host
     * @param $port
     * @param $database
     */
    public function setRedisConfiguration($host, $port, $database)
    {
        $this->redisConfiguration = [
            'host'     => $host,
            'port'     => $port,
            'database' => $database,
        ];
        $host = substr($host, 0, 1) == '/' ? $host : $host . ':' . $port;

        \Resque::setBackend($host, $database);
    }

    /**
     * @param Job $job
     * @param bool $trackStatus
     * @return null|\Resque_Job_Status
     */
    public function enqueueOnce(Job $job, $trackStatus = FALSE)
    {
        $queue = new Queue($job->queue);
        $jobs = $queue->getJobs();

        foreach ($jobs AS $j) {
            if ($j->job->payload['class'] == get_class($job)) {
                // Возможно, сравнение ошибочно. См. similarDelayedExist
                if (count(array_intersect($j->args, $job->args)) == count($job->args)) {
                    return ($trackStatus) ? $j->job->payload['id'] : NULL;
                }
            }
        }

        return $this->enqueue($job, $trackStatus);
    }

    /**
     * @param Job $job
     * @param bool $trackStatus
     * @return null|\Resque_Job_Status
     */
    public function enqueue(Job $job, $trackStatus = FALSE)
    {
        if ($job instanceof ContainerAwareJob) {
            $job->setKernelOptions($this->kernelOptions);
        }

        $this->attachRetryStrategy($job);

        $result = \Resque::enqueue($job->queue, \get_class($job), $job->args, $trackStatus);

        if ($trackStatus && $result !== FALSE) {
            return new \Resque_Job_Status($result);
        }

        return NULL;
    }

    /**
     * Attach any applicable retry strategy to the job.
     *
     * @param Job $job
     */
    protected function attachRetryStrategy($job)
    {
        $class = get_class($job);

        if (isset($this->jobRetryStrategy[$class])) {
            if (count($this->jobRetryStrategy[$class])) {
                $job->args['resque.retry_strategy'] = $this->jobRetryStrategy[$class];
            }
            $job->args['resque.retry_strategy'] = $this->jobRetryStrategy[$class];
        } elseif (count($this->globalRetryStrategy)) {
            $job->args['resque.retry_strategy'] = $this->globalRetryStrategy;
        }
    }

    /**
     * @param $at
     * @param Job $job
     * @return null
     */
    public function enqueueAt($at, Job $job)
    {
        if ($job instanceof ContainerAwareJob) {
            $job->setKernelOptions($this->kernelOptions);
        }

        $this->attachRetryStrategy($job);

        \ResqueScheduler::enqueueAt($at, $job->queue, \get_class($job), $job->args);

        return NULL;
    }

    public function enqueueUniqueAt($at, Job $job)
    {
        if ($this->similarDelayedExist($job)) {
            return null;
        }

        return $this->enqueueAt($at, $job);
    }

    /**
     * @param $in
     * @param Job $job
     * @return null
     */
    public function enqueueIn($in, Job $job)
    {
        if ($job instanceof ContainerAwareJob) {
            $job->setKernelOptions($this->kernelOptions);
        }

        $this->attachRetryStrategy($job);

        \ResqueScheduler::enqueueIn($in, $job->queue, \get_class($job), $job->args);

        return NULL;
    }

    public function enqueueUniqueIn($in, Job $job)
    {
        if ($this->similarDelayedExist($job)) {
            return null;
        }

        return $this->enqueueIn($in, $job);
    }

    /**
     * @param Job $job
     * @return mixed
     */
    public function removedDelayed(Job $job)
    {
        if ($job instanceof ContainerAwareJob) {
            $job->setKernelOptions($this->kernelOptions);
        }

        $this->attachRetryStrategy($job);

        return \ResqueScheduler::removeDelayed($job->queue, \get_class($job), $job->args);
    }

    /**
     * @param $at
     * @param Job $job
     * @return mixed
     */
    public function removeFromTimestamp($at, Job $job)
    {
        if ($job instanceof ContainerAwareJob) {
            $job->setKernelOptions($this->kernelOptions);
        }

        $this->attachRetryStrategy($job);

        return \ResqueScheduler::removeDelayedJobFromTimestamp($at, $job->queue, \get_class($job), $job->args);
    }

    /**
     * @return array
     */
    public function getQueues()
    {
        return \array_map(function($queue) {
            return new Queue($queue);
        }, \Resque::queues());
    }

    /**
     * @param $queue
     * @return Queue
     */
    public function getQueue($queue)
    {
        return new Queue($queue);
    }

    /**
     * @return Worker[]
     */
    public function getWorkers()
    {
        return \array_map(function($worker) {
            return new Worker($worker);
        }, \Resque_Worker::all());
    }

    /**
     * @return Worker[]
     */
    public function getRunningWorkers()
    {
        return array_filter($this->getWorkers(), function (Worker $worker) {
            return $worker->getCurrentJob() !== null;
        });
    }

    /**
     * @param $id
     * @return Worker|null
     */
    public function getWorker($id)
    {
        $worker = \Resque_Worker::find($id);

        if (!$worker) {
            return NULL;
        }

        return new Worker($worker);
    }

    /**
     * @return int
     */
    public function getNumberOfWorkers()
    {
        return \Resque::redis()->scard('workers');
    }

    /**
     * @return int
     */
    public function getNumberOfWorkingWorkers()
    {
        $count = 0;
        foreach ($this->getWorkers() as $worker) {
            if ($worker->getCurrentJob() !== null) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @todo - Clean this up, for now, prune dead workers, just in case
     */
    public function pruneDeadWorkers()
    {
        $worker = new \Resque_Worker('temp');
        $worker->setLogger(new NullLogger());
        $worker->pruneDeadWorkers();
    }

    /**
     * @return array|mixed
     */
    public function getFirstDelayedJobTimestamp()
    {
        $timestamps = $this->getDelayedJobTimestamps();
        if (count($timestamps) > 0) {
            return $timestamps[0];
        }

        return [NULL, 0];
    }

    /**
     * @return array
     */
    public function getDelayedJobTimestamps()
    {
        $timestamps = \Resque::redis()->zrange('delayed_queue_schedule', 0, -1);

        //TODO: find a more efficient way to do this
        $out = [];
        foreach ($timestamps as $timestamp) {
            $out[] = [$timestamp, \Resque::redis()->llen('delayed:' . $timestamp)];
        }

        return $out;
    }

    /**
     * @return mixed
     */
    public function getNumberOfDelayedJobs()
    {
        return \ResqueScheduler::getDelayedQueueScheduleSize();
    }

    /**
     * @param $timestamp
     * @return array
     */
    public function getJobsForTimestamp($timestamp)
    {
        $jobs = \Resque::redis()->lrange('delayed:' . $timestamp, 0, -1);
        $out = [];
        foreach ($jobs as $job) {
            $out[] = json_decode($job, TRUE);
        }

        return $out;
    }

    /**
     * @param $queue
     * @return int
     */
    public function clearQueue($queue)
    {
        return $this->getQueue($queue)->clear();
    }

    /**
     * @param int $start
     * @param int $count
     * @return array
     */
    public function getFailedJobs()
    {
        $result = [];

        foreach(\Resque::redis()->keys('failed:*') as $index => $key)
        {
            $key = \Resque::redis()->removePrefix($key);
            $jobs = \Resque::redis()->lrange($key, 0, -1);
            foreach ($jobs as $job) {
                $failedJob = new FailedJob(json_decode($job, TRUE));
                $parentId = $failedJob->getParentId();
                if ($parentId && $failedJob->getRetryAttempt() > 1) {
                    $id = $parentId;
                } else {
                    $id = $failedJob->getId();
                }
                if (!$result[$id]) {
                    $result[$id] = [];
                }
                $result[$id][] = $failedJob;
            }
            usort($result[$id], function ($a, $b) {
                return strtotime($a->getFailedAt()) - strtotime($b->getFailedAt());
            });
        }

        usort($result, function ($a, $b) {
            return strtotime($a[0]->getFailedAt()) - strtotime($b[0]->getFailedAt());
        });

        return $result;
    }

    /**
     * @return int
     */
    public function getNumberOfFailedJobs()
    {
        return count(\Resque::redis()->keys('failed:*'));
    }

    /**
     * @param bool $clear
     *
     * @return int
     */
    public function retryFailedJobs($id = null, $clear = false)
    {
        if ($id) {
            $key = 'failed:' . $id;
        } else {
            $key = 'failed:*';
        }
        $items = \Resque::redis()->keys($key);
        $length = count($items);
        foreach ($items as $key) {
            $key = \Resque::redis()->removePrefix($key);
            $jobs = \Resque::redis()->lrange($key, 0, -1);
            /** @var FailedJob $jobToEnqueue */
            $jobToEnqueue = null;
            foreach ($jobs as $job) {
                $failedJob = new FailedJob(json_decode($job, TRUE));
                if (!$jobToEnqueue || $jobToEnqueue->getRetryAttempt() < $failedJob->getRetryAttempt()) {
                    $jobToEnqueue = $failedJob;
                }
            }
            $args = $jobToEnqueue->getArgs()[0];
            if (!isset($args['resque.retry_attempt'])) {
                $args['resque.retry_attempt'] = 0;
                $args['resque.parent_id'] = $jobToEnqueue->getId();
            }
            $args['resque.retry_attempt']++;
            $this->clearScheduled($jobToEnqueue->getParentIdOrId());
            \Resque::enqueue($failedJob->getQueueName(), $jobToEnqueue->getName(), $args);
            if ($clear) {
                \Resque::redis()->del($key);
            }
        }
        return $length;
    }

    /**
     * @return int
     */
    public function clearFailedJobs($id = null)
    {
        if ($id) {
            $key = 'failed:' . $id;
        } else {
            $key = 'failed:*';
        }
        $items = \Resque::redis()->keys($key);
        $length = count($items);
        \Resque::redis()->del($key);
        return $length;
    }

    /**
     * @return int
     */
    public function clearScheduled($id)
    {
        if (!$id) {
            return;
        }
        $count = 0;

        foreach(\Resque::redis()->keys('delayed:*') as $key)
        {
            $key = \Resque::redis()->removePrefix($key);
            $jobs = \Resque::redis()->lrange($key, 0, -1);
            foreach ($jobs as $job) {
                $data = json_decode($job, TRUE);
                if (isset($data['args'][0]['resque.parent_id']) && $data['args'][0]['resque.parent_id'] == $id) {
                    $count += \Resque::redis()->lrem($key, 0, $job);
                }
            }
        }
        return $count;
    }


    private function similarDelayedExist(Job $job): bool {
        $jobs = [];
        $redis = \Resque::redis();
        // Нехорошо сюда тащить названия ключей из resque-scheduler. Но форкать ещё и его не хочется.
        foreach ($redis->keys("delayed:*") as $key) {
            $key = $redis->removePrefix($key);
            $delayed = $redis->lrange($key, 0, -1);
            foreach ($delayed as $serialized) {
                $jobs[] = json_decode($serialized, true);
            }
        }

        foreach ($jobs AS $j) {
            // Это $j['args'][0] из-за, по-видимому, ошибки в ResqueScheduler::jobToHash.
            // Там агрументы, зачем-то, заворачиваются в массив ещё раз. Поэтому прямое
            // сравнение $j['args'] и $job->args будет неверным.
            // Предполагаю что в enqueueOnce оно тоже не сработает.
            if ($j['class'] == get_class($job)
                    && count(array_intersect_assoc($j['args'][0], $job->args)) == count($job->args)) {
                return true;
            }
        }

        return false;
    }
}
