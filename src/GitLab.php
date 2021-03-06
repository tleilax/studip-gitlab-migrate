<?php

namespace Trac2GitLab;

define('USER_FETCH_PAGE_SIZE', 1000);
define('USER_FETCH_MAX_PAGES', 50);

use Gitlab\Client;
use Gitlab\Model\Issue;
use Gitlab\HttpClient\Builder;

/**
 * GitLab communicator class
 *
 * @author  dachaz
 */
class GitLab
{
    private $client;
    private $url;
    private $isAdmin;

    /**
     * Constructor
     *
     * @param  string    $url         GitLab URL
     * @param  string    $token       GitLab API private token
     * @param  boolean   $isAdmin     Indicates that the GitLab token is from an admin user
     */
	public function __construct($url, $token, $isAdmin) {
		$this->url = $url;
        $this->client = Client::create($url . '/api/v4/');
		$this->client->authenticate($token, Client::AUTH_URL_TOKEN);
		$this->isAdmin = $isAdmin;
	}


	/**
 	 * Tries to fetch all the users from GitLab. Will get at most
 	 * (USER_FETCH_PAGE_SIZE * USER_FETCH_MAX_PAGES) users. Returns
 	 * a map of {username => userInfoObject}
 	 *
 	 * @return  array
 	 */
	public function listUsers() {
		$users = array();
		$gotAll = false;
		$page = 1;
		// Stop when we either have all users or we have exhausted the sane number of attempts
		while (!$gotAll && $page < USER_FETCH_MAX_PAGES) {
			$response = $this->client->api('users')->all([], $page++, USER_FETCH_PAGE_SIZE);
			foreach($response as $user) {
				$users[$user['username']] = $user;
				// We assume that 'Administrator' user is in there
				$gotAll = $user['id'] == 1;
			}
		}
		return $users;
	}

	/**
	 * Creates a new issue in the given project. When working in admin mode, tries to create the issue
	 * as the given author (SUDO) and if that fails, tries creating the ticket again as the admin.
	 * @param  mixed    $projectId    Numeric project id (e.g. 17) or the unique string identifier (e.g. 'dachaz/trac-to-gitlab')
     * @param  string   $title        Title of the new issue
     * @param  string   $description  Description of the new issue
     * @param  string     $createdAt    Custom creation date
     * @param  int      $assigneeId   Numeric user id of the user asigned to the issue. Can be null.
     * @param  int      $authorId     Numeric user id of the user who created the issue. Only used in admin mode. Can be null.
     * @param  array    $labels       Array of string labels to be attached to the issue. Analoguous to trac keywords.
	 * @param  bool     $confidential Is this issue confidential?
	 * @para,  int      $milestoneId  Optional ID of a milestone to assign this issue to
     * @return  Gitlab\Model\Issue
	 */
	public function createIssue($projectId, $title, $description, $createdAt, $assigneeId, $authorId, $labels,
								$confidential = false, $milestoneId = 0
	) {
		try {
			// Try to add, potentially as an admin (SUDO authorId)
			$issue = $this->doCreateIssue($projectId, $title, $description, $createdAt, $assigneeId, $authorId,
				$labels, $confidential, $milestoneId, $this->isAdmin);
		} catch (\Gitlab\Exception\RuntimeException $e) {
			// If adding has failed because of SUDO (author does not have access to the project), create an issue without SUDO (as the Admin user whose token is configured)
			if ($this->isAdmin) {
				$issue = $this->doCreateIssue($projectId, $title, $description, $createdAt, $assigneeId, $authorId,
					$labels, $confidential, $milestoneId, false);
			} else {
				// If adding has failed for some other reason, propagate the exception back
				throw $e;
			}
		}
		return $issue;
	}

    /**
	 * Closes an issue.
     * @param mixed $projectId project ID or path
     * @param int $issueId issue to close
     * @param string $time time in ISO8601 format
	 * @param string $author wh closed the issue?
     */
	public function closeIssue($projectId, $issueId, $time = '', $author = '') {
		$this->client->api('issues')->update($projectId, $issueId,
			array_filter([
				'state_event' => 'close',
				'updated_at' => $time,
				'closed_at' => $time,
				'closed_by_id' => $author,
				'closed_by' => $author
			]));
	}

	/**
	 * Creates a new note in the given project and on the given issue id (NOTE: id, not iid). When working in admin mode, tries to create the note
	 * as the given author (SUDO) and if that fails, tries creating the note again as the admin.
	 * @param  mixed    $projectId    Numeric project id (e.g. 17) or the unique string identifier (e.g. 'dachaz/trac-to-gitlab')
     * @param  int      $issueId      Unique identifier of the issue
     * @param  string   $text         Text of the note
     * @param  int      $authorId     Numeric user id of the user who created the issue. Only used in admin mode. Can be null.
     * @return  Gitlab\Model\Note
	 */
	public function createNote($projectId, $issueId, $text, $authorId) {
		try {
			// Try to add, potentially as an admin (SUDO authorId)
			$note = $this->doCreateNote($projectId, $issueId, $text, $authorId, $this->isAdmin);
		} catch (\Gitlab\Exception\RuntimeException $e) {
			// If adding has failed because of SUDO (author does not have access to the project), create an issue without SUDO (as the Admin user whose token is configured)
			if ($this->isAdmin) {
				$note = $this->doCreateNote($projectId, $issueId, $text, $authorId, false);
			} else {
				// If adding has failed for some other reason, propagate the exception back
				throw $e;
			}
		}
		return $note;
	}

    /**
	 * Attaches a file to an issue.
	 *
     * @param mixed $projectId numerical ID or path to target project
	 * @param int $issueId the issue to add the file to
     * @param string $file base64 representation of file, we'll see if that works.
     * @param int $authorId the file author
     */
	public function createIssueAttachment($projectId, $issueId, $file, $authorId) {
		try {
			// First, add file to project.
            $data = $this->client->api('projects')->uploadFile($projectId, $file);

			// Add the uploaded file as a note on the given issue.
            $note = $this->createNote($projectId, $issueId, $data['markdown'], $authorId);

			// Update possible mentions of this file in issue description.
			$issue = $this->client->api('issues')->show($projectId, $issueId);

			$description = str_replace(
				'[[br]]',
				'<br>',
				preg_replace('/!\[image\]\((.+),.*\)/U', $data['markdown'], $issue['description'])
			);

			$this->client->api('issues')->update($projectId, $issueId, ['description' => $description]);

			return $note;

        } catch (\Gitlab\Exception\RuntimeException $e) {
            // If adding has failed because of SUDO (author does not have access to the project), create an issue without SUDO (as the Admin user whose token is configured)
            if ($this->isAdmin && isset($data)) {
                $note = $this->doCreateNote($projectId, $issueId, $data['markdown'], $authorId, false);
            } else {
                // If adding has failed for some other reason, propagate the exception back
                throw $e;
            }
		}
    }

    /**
	 * Gets milestones of project.
	 * @param string $projectId project to check
	 * @param array  $ids get only milestones with the given IDs.
	 * @param string $state if set, return only milestones with the given status
     * @param string $search optional filter on milestone title or description
	 * @return array
     */
    public function getMilestones($projectId, $ids = [], $state = '', $search = '') {
    	$parameters = [];
    	if (count($ids) > 0) {
    		$parameters['iids'] = $ids;
		}
		if ($state !== '') {
    		$parameters['state'] = $state;
		}
		if ($search !== '') {
    		$parameters['search'] = $search;
		}
		return $this->client->api('milestones')->all($projectId, $parameters);
	}

	public function createMilestone($projectId, $title, $description = '', $dueDate = '', $startDate = '') {
		return $this->client->api('milestones')->create($projectId,
			['title' => $title, 'description' => $description, 'due_date' => $dueDate, 'start_date' => $startDate]);
	}

	public function closeMilestone($projectId, $id) {
    	return $this->client->api('milestones')->update($projectId, $id, ['state_event' => 'close']);
	}

	// Actually creates the issue
	private function doCreateIssue($projectId, $title, $description, $createdAt, $assigneeId, $authorId, $labels,
								   $confidential, $milestoneId = 0, $isAdmin
	) {
		$issueProperties = array(
			'title' => $title,
			'description' => $description,
			'assignee_id' => $assigneeId,
			'labels' => $labels,
            'created_at' => $createdAt
		);
		if ($confidential) {
			$issueProperties['confidential'] = true;
        }
		if ($milestoneId !== '') {
			$issueProperties['milestone_id'] = $milestoneId;
        }
		if ($isAdmin) {
			$issueProperties['sudo'] = $authorId;
		}
		return $this->client->api('issues')->create($projectId, $issueProperties);
	}

	// Actually creates the note
	private function doCreateNote($projectId, $issueId, $text, $authorId, $isAdmin) {
		$noteProperties = array(
			'body' => $text
		);
		if ($isAdmin) {
			$noteProperties['sudo'] = $authorId;
		}
		return $this->client->api('issues')->addComment($projectId, $issueId, $noteProperties);
	}

	/**
	 * Returns the URL of this GitLab installation.
	 * @return string
	 */
	public function getUrl() {
		return $this->url;
	}

    public function getClient()
    {
        return $this->client;
    }
}
?>
