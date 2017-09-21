<?php

namespace ResqueBundle\Resque;

/**
 * Class FailedJob
 * @package ResqueBundle\Resque
 */
class FailedJob
{
    /**
     * @var array
     */
    protected $data;

    /**
     * @param array $data Contains the failed job data
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * @return mixed
     */
    public function getFailedAt()
    {
        return $this->data['failed_at'];
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->data['payload']['class'];
    }

    /**
     * @return int
     */
    public function getId()
    {
        return $this->data['payload']['id'];
    }

    /**
     * @return int
     */
    public function getParentId()
    {
        $args = $this->getArgs();
        if ($args && count($args) && isset($args[0]['resque.parent_id'])) {
            return $args[0]['resque.parent_id'];
        }
        return null;
    }

    /**
     * @return int
     */
    public function getParentIdOrId()
    {
        $args = $this->getArgs();
        if ($args && count($args) && isset($args[0]['resque.parent_id'])) {
            return $args[0]['resque.parent_id'];
        }
        return $this->getId();
    }

    /**
     * @return string
     */
    public function getQueueName()
    {
        return $this->data['queue'];
    }

    /**
     * @return string
     */
    public function getWorkerName()
    {
        return $this->data['worker'];
    }

    /**
     * @return string
     */
    public function getExceptionClass()
    {
        return $this->data['exception'];
    }

    /**
     * @return string
     */
    public function getError()
    {
        return $this->data['error'];
    }

    /**
     * @return mixed
     */
    public function getBacktrace()
    {
        return $this->data['backtrace'];
    }

    /**
     * @return array
     */
    public function getArgs()
    {
        return $this->data['payload']['args'];
    }

    /**
     * @return array
     */
    public function getRetryAttempt()
    {
        $args = $this->getArgs();
        if ($args && count($args) && isset($args[0]['resque.retry_attempt'])) {
            return (int) $args[0]['resque.retry_attempt'] + 1;
        }
        return 1;
    }

}
