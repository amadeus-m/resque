<?php

namespace ResqueBundle\Resque\Controller;

use ResqueBundle\Resque\Resque;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class DefaultController
 * @package ResqueBundle\Resque\Controller
 */
class DefaultController extends Controller
{
    /**
     * @return Response
     */
    public function indexAction()
    {
        $this->getResque()->pruneDeadWorkers();

        return $this->render(
            'ResqueBundle:Default:index.html.twig',
            [
                'resque' => $this->getResque(),
            ]
        );
    }

    /**
     * @return Resque
     */
    protected function getResque()
    {
        return $this->get('resque');
    }

    /**
     * @param $queue
     * @param Request $request
     * @return Response
     */
    public function showQueueAction($queue, Request $request)
    {
        list($start, $count, $showingAll) = $this->getShowParameters($request);

        $queue = $this->getResque()->getQueue($queue);
        $jobs = $queue->getJobs($start, $count);

        if (!$showingAll) {
            $jobs = array_reverse($jobs);
        }

        return $this->render(
            'ResqueBundle:Default:queue_show.html.twig',
            [
                'queue'      => $queue,
                'jobs'       => $jobs,
                'showingAll' => $showingAll
            ]
        );
    }

    public function removeQueueAction($queue, Request $request)
    {
        $queue = $this->getResque()->getQueue($queue);
        $count = $queue->clear();
        $queue->remove();

        $this->addFlash('info', 'Remove ' . $queue->getName() . ' queue and ' . $count . ' jobs.');

        return $this->redirectToRoute('ResqueBundle_homepage');
    }


    /**
     * Decide which parts of a job queue to show
     *
     * @param Request $request
     *
     * @return array
     */
    private function getShowParameters(Request $request)
    {
        $showingAll = FALSE;
        $start = -100;
        $count = -1;

        if ($request->query->has('all')) {
            $start = 0;
            $count = -1;
            $showingAll = TRUE;
        }

        return [$start, $count, $showingAll];
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function listFailedAction(Request $request)
    {
        $jobs = $this->getResque()->getFailedJobs();

        return $this->render(
            'ResqueBundle:Default:failed_list.html.twig',
            [
                'jobs'       => $jobs,
                'showingAll' => true,
            ]
        );
    }

    /**
     * @return Response
     */
    public function workersAction()
    {
        return $this->render(
            'ResqueBundle:Default:workers.html.twig',
            [
                'resque' => $this->getResque(),
            ]
        );
    }

    /**
     * @return Response
     */
    public function listScheduledAction()
    {
        return $this->render(
            'ResqueBundle:Default:scheduled_list.html.twig',
            [
                'timestamps' => $this->getResque()->getDelayedJobTimestamps()
            ]
        );
    }

    /**
     * @param $timestamp
     * @return Response
     */
    public function showTimestampAction($timestamp)
    {
        $jobs = [];

        // we don't want to enable the twig debug extension for this...
        foreach ($this->getResque()->getJobsForTimestamp($timestamp) as $job) {
            $jobs[] = print_r($job, TRUE);
        }

        return $this->render(
            'ResqueBundle:Default:scheduled_timestamp.html.twig',
            [
                'timestamp' => $timestamp,
                'jobs'      => $jobs
            ]
        );
    }

    /**
     * @return RedirectResponse
     */
    public function retryFailedAction(Request $request)
    {
        $id = $request->get('id', null);
        $count = $this->getResque()->retryFailedJobs($id);

        $this->addFlash('info', 'Retry '.$count.' failed jobs.');

        return $this->redirectToRoute('ResqueBundle_homepage');
    }

    /**
     * @return RedirectResponse
     */
    public function retryClearFailedAction(Request $request)
    {
        $id = $request->get('id', null);
        $count = $this->getResque()->retryFailedJobs($id, true);

        $this->addFlash('info', 'Retry and clear '.$count.' failed jobs.');

        return $this->redirectToRoute('ResqueBundle_homepage');
    }

    /**
     * @return RedirectResponse
     */
    public function clearFailedAction(Request $request)
    {
        $id = $request->get('id', null);
        $count = $this->getResque()->clearFailedJobs($id);

        $this->addFlash('info', 'Clear '.$count.' failed jobs.');

        return $this->redirectToRoute('ResqueBundle_homepage');
    }

    /**
     * @return RedirectResponse
     */
    public function clearScheduledAction(Request $request)
    {
        $id = $request->get('id', null);
        $count = $this->getResque()->clearScheduled($id);

        $this->addFlash('info', 'Clear '.$count.' scheduled jobs.');

        return $this->redirectToRoute('ResqueBundle_homepage');
    }

}
