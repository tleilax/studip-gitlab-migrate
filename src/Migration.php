<?php

namespace Trac2GitLab;

/**
 * The class that actually migrates the tickets
 *
 * @author  dachaz
 */
class Migration
{
	// Communicators
	public $gitLab;
	public $trac;
	// Configuration
	private $addLinkToOriginalTicket;
	private $userMapping;
	// Cache
	private $gitLabUsers;

	/**
     * Constructor
     *
     * @param  string    $gitLabUrl                 GitLab URL
     * @param  string    $gitLabToken               GitLab API private token
     * @param  boolean   $gitLabTokenIsAdmin        Indicates that the GitLab token is from an admin user
     * @param  string    $tracUrl                   Trac URL
     * @param  boolean   $addLinkToOriginalTicket   Whether a link to the Trac ticket should be added at the end of the GitLab issue
     * @param  array     $userMapping               A map of {tracUsername => gitLabUsername}
     */
	public function __construct($gitLabUrl, $gitLabToken, $gitLabTokenIsAdmin, $tracUrl, $addLinkToOriginalTicket, $userMapping) {
		$this->gitLab = new GitLab($gitLabUrl, $gitLabToken, $gitLabTokenIsAdmin);
		$this->trac = new Trac($tracUrl);
		$this->addLinkToOriginalTicket = $addLinkToOriginalTicket;
		$this->userMapping = $userMapping;
	}

	/**
     * Migrates open tickets for a single Trac component into the provided GitLab project.
     *
     * @param  string    $tracComponentName         Trac component to be migrated
     * @param  string    $gitLabProject             GitLab project in which the issues should be created
     */
	public function migrateComponent($tracComponentName, $gitLabProject) {
		$openTickets = $this->trac->listOpenTicketsForComponent($tracComponentName);
		return $this->migrate($openTickets, $gitLabProject);
	}

	/**
     * Migrates all tickets matching a custom Trac query into the provided GitLab project.
     *
     * @param  string    $tracQuery                 Trac query to be executed in order to find tickets
     * @param  string    $gitLabProject             GitLab project in which the issues should be created
     */
	public function migrateQuery($tracQuery, $gitLabProject) {
		$openTickets = $this->trac->listTicketsForQuery($tracQuery);
		return $this->migrate($openTickets, $gitLabProject);
	}

	/**
     * Returns a GitLab user object for the given Trac username. If a user mapping has been provided, tries to fetch the user based on the mapped username.
     * If no mapping is found, tries fetching the user with the same username as in trac. If no matching user is found in GitLab, returns null.
     *
     * @param  string    $tracComponentName         Trac component to be migrated
     * @param  string    $gitLabProject             GitLab project in which the issues should be created
     * @return Gitlab\Model\User
     */
	private function getGitLabUser($tracUser) {
		if (!is_array($this->gitLabUsers)) {
			$this->fetchGitlabUsers();
		}

		$lookup = $tracUser;
		if (is_array($this->userMapping) && isset($this->userMapping[$tracUser])) {
			$lookup = $this->userMapping[$tracUser];
		}

		return isset($this->gitLabUsers[$lookup]) ? $this->gitLabUsers[$lookup] : null;
	}

	/**
	 * Fetches all users from GitLab and stores them in the internal cache.
	 */
	private function fetchGitLabUsers() {
		$this->gitLabUsers = $this->gitLab->listUsers();
	}


	/**
	 * Performs the actual migration.
	 *
	 * @param  array     $openTickets               Array of Trac tickets to be migrated
	 * @param  string    $gitLabProject             GitLab project in which the issues should be created
	 */
	public function migrate($openTickets, $gitLabProject) {
        $mapping = [];
		foreach($openTickets as $ticket) {
			$originalTicketId = $ticket[0];
			$title = $ticket[3]['summary'] ?: '¯\_(ツ)_/¯';
			$description = $this->translateTracToMarkdown($ticket[3]['description']);
			if ($this->addLinkToOriginalTicket) {
				$description .= "\n\n---\n\nOriginal ticket: " . $this->trac->getUrl() . '/ticket/' . $originalTicketId;
			}
			$gitLabAssignee = $this->getGitLabUser($ticket[3]['owner']);
			$gitLabCreator = $this->getGitLabUser($ticket[3]['reporter']);
			$assigneeId = is_array($gitLabAssignee) ? $gitLabAssignee['id'] : null;
			$creatorId = is_array($gitLabCreator) ? $gitLabCreator['id'] : null;
			$labels = $ticket[3]['keywords'];
            $dateCreated = $ticket[3]['time']['__jsonclass__'][1];
            $dateUpdated = $ticket[3]['_ts'];
            $confidential = (bool) @$ticket[3]['sensitive'];

            $attachments = $this->trac->getAttachments($originalTicketId);

			$issue = $this->gitLab->createIssue($gitLabProject, $title,
				$description, $dateCreated, $assigneeId, $creatorId, $labels,
				$confidential);

			echo 'Created a GitLab issue #' . $issue['iid'] . ' for Trac ticket #' . $originalTicketId . ' : ' .
				$this->gitLab->getUrl() . '/' . $gitLabProject . '/issues/' . $issue['iid'] . "\n";

            $mapping[$originalTicketId] = $issue['iid'];

			// If there are comments on the ticket, create notes on the issue
			/*if (is_array($ticket[4]) && count($ticket[4])) {
				foreach($ticket[4] as $comment) {
					$commentAuthor = $this->getGitLabUser($comment['author']);
					$commentAuthorId = is_array($commentAuthor) ? $commentAuthor['id'] : null;
					$commentText = $this->translateTracToMarkdown($comment['text']);
					$note = $this->gitLab->createNote($gitLabProject, $issue['iid'], $commentText, $commentAuthorId);
				}
				echo "\tAlso created " . count($ticket[4]) . " note(s)\n";
			}*/

			/*
			 * Create a transliterator for treating file names with special
			 * characters in them.
			 */
			$trans = \Transliterator::create('Latin-ASCII');
            /*
             * Add files attached to Trac ticket to new Gitlab issue.
             */
			foreach ($attachments as $a) {

				// Transliterate file name, using only "safe" characters.
				$filename = $trans->transliterate($a['filename']);

				file_put_contents($filename, base64_decode($a['content']));

				$this->gitLab->createIssueAttachment($gitLabProject, $issue['iid'], $filename, $a['author']);
                unlink($filename);

                echo "\tAttached file " . $filename . " to issue " . $issue['iid'] . ".\n";
            }

			// Close issue if Trac ticket was closed.
			if ($ticket[3]['status'] === 'closed') {
				$this->gitLab->closeIssue($gitLabProject, $issue['iid'],
                    isset($ticket[4]) ? $ticket[4][0]['time']['__jsonclass__'][1] : $ticket[3]['_ts'],
                    isset($ticket[4]) ? $ticket[4][0]['author'] : '');
			}

		}
        return $mapping;
	}

	/**
	 * Converts the Trac WikiFormatting into GitLab Flavoured Markdown
	 *
	 * @param  string    $text                      Text in WikiFormatting
	 * @return  string
	 */
	// Adapted from: https://gitlab.dyomedea.com/vdv/trac-to-gitlab/blob/master/trac2down/Trac2Down.py
	public function translateTracToMarkdown($text) {
		$text = str_replace("\r\n", "\n", $text);
		// Inline code block
		$text = preg_replace('/{{{(.*?)}}}/', '`$1`', $text);
		// Multiline code block (optionally with language description)
		$text = preg_replace("/{{{\n(?:#!(.+?)\n)?(.*?)\n}}}/s", "```\$1\n\$2\n```", $text);

		// Headers
		$text = preg_replace('/(?m)^======\s+(.*?)(\s+======)?$/', '###### $1', $text);
		$text = preg_replace('/(?m)^=====\s+(.*?)(\s+=====)?$/', '##### $1', $text);
		$text = preg_replace('/(?m)^====\s+(.*?)(\s+====)?$/', '#### $1', $text);
		$text = preg_replace('/(?m)^===\s+(.*?)(\s+===)?$/', '### $1', $text);
		$text = preg_replace('/(?m)^==\s+(.*?)(\s+==)?$/', '## $1', $text);
		$text = preg_replace('/(?m)^=\s+(.*?)(\s+=)?$/', '# $1', $text);
		// Bullet points
		$text = preg_replace('/^             \* /', '****', $text);
		$text = preg_replace('/^         \* /', '***', $text);
		$text = preg_replace('/^     \* /', '**', $text);
		$text = preg_replace('/^ \* /', '*', $text);
		$text = preg_replace('/^ \d+\. /', '1.', $text);
		// Make sure that horizontal rules have a line before them
		$text = preg_replace("/(?m)^-{4,}$/", "\n----", $text);

		$lines = array();
		$isTable = false;
		$isCode  = false;
		foreach (explode("\n", $text) as $line) {
			if (Utils::startsWith($line, '```')) {
				$isCode = !$isCode;
			}

			// Don't mess with code
			if (!$isCode) {
				// External links
				$line = preg_replace('/\[(https?:\/\/[^\s\[\]]+)\s([^\[\]]+)\]/', '[$2]($1)', $line);
				// Plain images (not linking to something specific)
				$line = preg_replace('/\[\[Image\((?!wiki|ticket|htdocs|source)(.+?)\)\]\]/', '![image]($1)', $line);
	            // Remove the unnecessary exclamation mark in !WikiLinkBreaker
	            $line = preg_replace('/\!(([A-Z][a-z0-9]+){2,})/', '$1', $line);
	            // '''bold'''
	            $line = preg_replace("/'''([^']*?)'''/", '**$1**', $line);
	            // ''italic''
	            $line = preg_replace("/''(.*?)''/", '_$1_', $line);
	            // //italic//
	            $line = preg_replace("/\/\/(.*?)\/\//", '_$1_', $line);
	            // #Ticket links
	            $line = preg_replace('/#(\d+)/', '[#$1](' . $this->trac->getUrl() . '/ticket/$1)', $line);
	            $line = preg_replace('/ticket:(\d+)/', '[ticket:$1](' . $this->trac->getUrl() . '/ticket/$1)', $line);
	            // [changeset] links
	            $line = preg_replace('/\[(\d+)\]/', '[[$1]](' . $this->trac->getUrl() . '/changeset/$1)', $line);
	            $line = preg_replace('/changeset:(\d+)/', '[changeset:$1](' . $this->trac->getUrl() . '/changeset/$1)', $line);
	            $line = preg_replace('/r(\d+)/', '[r$1](' . $this->trac->getUrl() . '/changeset/$1)', $line);
	            // {report} links
	            $line = preg_replace('/{(\d+)}/', '[{$1}](' . $this->trac->getUrl() . '/report/$1)', $line);
	            $line = preg_replace('/report:(\d+)/', '[report:$1](' . $this->trac->getUrl() . '/report/$1)', $line);

	            if (!Utils::startsWith($line, '||')) {
	            	$isTable = false;
	            } else {
	            	// Makes sure both that there's a new line before the table and that a table header is generated
	            	if (!$isTable) {
	            		$sep = preg_replace('/[^|]/', '-', $line);
	            		$line = "\n$line\n$sep";
	            		$isTable = true;
	            	}
	            	// Makes sure that there's a space after the cell separator, since |cell| works in WikiFormatting but not in GFM
	            	$line = preg_replace('/\|\|/', '| ', $line);
	            	// Make trac headers bold
	            	$line = preg_replace('/= (.+?) =/', '**$1**', $line);
	            }
	        }

			$lines[] = $line;
		}
		$text = implode("\n", $lines);

		return $text;
	}
}
