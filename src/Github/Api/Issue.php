<?php

namespace TracHandler\Github\Api;


/*
 * Overridding \Github\Client to use the preview ticket import functionality
 * @link https://gist.github.com/jonmagic/5282384165e0f86ef105#start-an-issue-import
*/
class Issue extends \Github\Api\Issue
{

    /**
     * Create a new issue for the given username and repo.
     * The Import endpoint will not create any notifications and allows more content
     * The issue is assigned to the authenticated user. Requires authentication.
     *
     * @link http://developer.github.com/v3/issues/
     * @link https://gist.github.com/jonmagic/5282384165e0f86ef105#start-an-issue-import
     *
     * @param string $username   the username
     * @param string $repository the repository
     * @param array  $params     the new issue data
     *
     * @throws MissingArgumentException
     *
     * @return array information about the issue
     */
    public function import($username, $repository, array $params)
    {
        if (!isset($params['issue'])) {
            throw new MissingArgumentException(array('issue'));
        }
        $this->configure();
        return $this->post('/repos/'.rawurlencode($username).'/'.rawurlencode($repository).'/import/issues', $params);
    }

}
