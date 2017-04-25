<?php
namespace TracHandler\Github\Api;

use TracHandler\Github\Api\Issue;

/*
 * Overridding \Github\Client to use the preview ticket import functionality
 * @link https://gist.github.com/jonmagic/5282384165e0f86ef105#start-an-issue-import
*/
class ImportClient extends \Github\Client
{

    /**
     * Overridding this entire method to use alternate issue class
     *
     * @param string $name
     *
     * @throws InvalidArgumentException
     *
     * @return ApiInterface
     */
    public function api($name)
    {
        if ($name !== 'issue') {
            return parent::api($name);
        } else {
            return new Issue($this);
        }
    }


}