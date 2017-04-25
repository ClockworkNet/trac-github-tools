<?php
namespace TracHandler\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Helper\Table;
use TracHandler\Csv\CsvImporter;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use TracHandler\Github\Api\ImportClient as Client;

class AddCSVToGithubCommand extends Command
{

    protected $client;
    protected $owner;
    protected $repo;
    protected $user;
    protected $collaborators = [];
    protected $milestones = [];
    protected $issues = [];

    protected function configure()
    {
        $this
            // the name of the command (the part after "bin/console")
            ->setName('import:csv-to-github')

            // the short description shown while running "php bin/console list"
            ->setDescription('Imports a csv file to github.')

            // the full command description shown when running the command with
            // the "--help" option
            ->setHelp('This command takes a csv file listing trac issues and adds them to a Github repo')
            ->addOption('columns', '-c',InputOption::VALUE_NONE, 'Show valid csv columns', null)
            ->addOption('token', '-t',InputOption::VALUE_NONE, 'Use GITHUB_API_TOKEN env var for auth', null)
            ->addOption('repo', null ,InputOption::VALUE_REQUIRED, 'Full owner and repo e.g. "tmulry/TestRepo"', null)
            ->addOption('user', null ,InputOption::VALUE_REQUIRED, 'Github username.  must be authorized for selected repo', null)

            ->addArgument('filename',InputArgument::OPTIONAL, 'csv file to import');
            //todo pull input from pipe?

        $this->supported_columns = [
            'type','owner','status','milestone','keywords','summary','description'
        ];
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

        if ($input->getOption('columns')) {
            $this->dumpColumns($output);
            return 0;
        }


        if ($input->getOption('token')){
            //using a personal API token and preset password to skip password prompt
            $password = 'x-oauth-basic';
            $this->user = getenv('GITHUB_API_TOKEN');
            if ( !$this->user ){
                throw new InvalidArgumentException("The GITHUB_API_TOKEN env var was not set", 1);
            }
        } else {
            //get github password from hidden prompt
            $helper = $this->getHelper('question');
            $question = new Question('Please enter your Github password: ', false);
            $question->setHidden(true);
            $question->setHiddenFallback(false);
            $password = $helper->ask($input, $output, $question);

            if (!$password) {
                return;
            }

            $this->user  =  $input->getOption('user');
        }

        $this->client = new Client(null,'golden-comet-preview');

        //pull owner and repo from owner/repo format
        list($this->owner, $this->repo) = explode('/', $input->getOption('repo'));

        $this->client->authenticate($this->user, $password, Client::AUTH_HTTP_PASSWORD);

        $this->user_name = $this->client->api('current_user')->show()["login"];

        $this->repoData = $this->client->api('repo')->show($this->owner,$this->repo);



        if($this->repoData["private"] === false){
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion('You are loading issues to a public repository, Continue with this action (y/N)? ', false);

            if (!$helper->ask($input, $output, $question)) {
                return;
            };
        }

        if($this->repoData["permissions"]["push"] === false){
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion('You do not have access to set Assignees, Milestone or Labels, Continue with this action (y/N)? ', false);

            if (!$helper->ask($input, $output, $question)) {
                return;
            };
        }


        //load file data and parse header
        $csv_data_source  =  $this->getFileStream( $input );
        $csv              =  new CsvImporter($csv_data_source, true);
        $issue_data       =  $csv->get();
        //check header for extra columns
        if (empty($csv->getHeader()[0]) ) {
            throw new InvalidArgumentException("CSV Input contained an invalid header row", 1 );
        }
        foreach ($csv->getHeader() as $key => $value) {
            if ( !in_array($value, $this->supported_columns) ){
                throw new InvalidArgumentException("CSV header did not match supported column titles", 1 );
                return;
            }
        }


        $tabular_output = [];
        foreach ($issue_data as $key => $issue) {
            $body          =  $this->parseBody($issue["description"], $input, $output);

            if ($body === null){
                return;
            }
            if ($key > 1){
                //Github cries foul if you try to POST too many issues at once, so do 1/second
                // this does not seem to be necessary when using Import vs create
                // sleep(1);
            }

            //return null or the number of the given milestone
            $milestone_id  =  $this->findMilestoneNumber($issue["milestone"]);
            $labels        =  $this->parseKeywords($issue["keywords"]);
            $assignees     =  ($issue["owner"]=="nobody" || $issue["owner"]=="" ) ? array($this->verifyUserHandle($this->user_name)) : array($this->verifyUserHandle($issue["owner"]));
            $title         =  $issue["summary"];
            $issue_id      =  $this->findIssueNumber($title);

            //update existing issue if it matches one that is already open

            if ( !$issue_id ) {
                $issue = $this->client->api('issue')->configure()->import($this->owner, $this->repo, [ "issue" => $this->buildImportedIssue($title, $body, $assignees[0], $milestone_id, $labels ) ]);
                $output->writeln( "Imported: " . $issue["url"]);
            } else {
                $issue = $this->client->api('issue')->configure()->update($this->owner, $this->repo, $issue_id, $this->buildExistingIssue($title, $body ) );
                $output->writeln( "Updated: " . $issue["url"]);
            }
            $known_issues  =  $this->listIssues( );
            array_push( $known_issues, $issue["url"] );
            array_push( $tabular_output, array($title , $issue["url"]) );
        }


        $table = new Table($output);
        $table
            ->setHeaders(array('Description', 'URL'))
            ->setStyle('borderless')
            ->setRows($tabular_output);

        $table->render();

    }

    protected function dumpColumns( $output)
    {
        $output->writeln([
            'Available Columns',
            '============',
            '',
        ]);

        $output->writeln($this->supported_columns);
        $output->writeln('');

    }

    protected function verifyUserHandle( $handle )
    {
        try {
            $user = $this->client->api('user')->show($handle);
            if ($this->findCollaborator($handle) === null) {
                return null;
            }
        } catch (\Github\Exception\RuntimeException $e) {
            return null;
        }
        return $user["login"];
    }

    protected function listMilestones( )
    {
        if ( empty($this->milestones) ) {
            $this->milestones = $this->client->api('issue')->milestones()->all($this->owner, $this->repo);

        }
        return $this->milestones;
    }

    protected function listCollaborators( )
    {
        if ( empty($this->collaborators) ) {

            $this->collaborators = $this->client->api('repo')->collaborators()->all($this->owner,$this->repo);
        }
        return $this->collaborators;
    }

    protected function listIssues( )
    {
        if ( empty($this->issues) ) {
            $this->issues  =  $this->client->api('issue')->all($this->owner,$this->repo, array('state' => 'open'));
        }
        return $this->issues;
    }

    //return null or the number of the matching milestone
    protected function findMilestoneNumber($title)
    {
        $milestone_list = $this->listMilestones( );
        foreach ($milestone_list as $key => $milestone) {
            if ( strtoupper($title) === strtoupper($milestone["title"]) ){
                return $milestone["number"];
            }
        }

        return null;
    }

    protected function findIssueNumber($summary)
    {
        $issue_list = $this->listIssues( );
        foreach ($issue_list as $key => $issue) {
            if ( strtoupper($summary) === strtoupper($issue["title"]) ){
                return $issue["number"];
            }
        }

        return null;
    }

    protected function findCollaborator($handle)
    {
        $collaborator_list = $this->listCollaborators( );
        foreach ($collaborator_list as $key => $collaborator) {
            if ( strtoupper($handle) === strtoupper($collaborator["login"]) ){
                return $collaborator["login"];
            }
        }

        return null;
    }


    protected function getFileStream($input)
    {
        $filename = $input->getArgument('filename');
        if ($filename ) {
            if ( !file_exists( $filename ) ) {
                throw new InvalidArgumentException("File (" . $filename .  ") Does not exist", 1);
            }
            return $filename;
        // TODO STDIN input does not work because it masks Symfony command line prompt questions...
        // } else if (0 === ftell(STDIN)) {
        //     return 'php://stdin';
        } else {
            throw new \RuntimeException("Please provide a filename.");
            // throw new \RuntimeException("Please provide a filename or pipe csv content to STDIN.");
        }
    }

    protected function parseKeywords($keywords)
    {
        //parse string to array, splitting on [] and ,
        return preg_split("[,|\[|\]]", $keywords, -1, PREG_SPLIT_NO_EMPTY);
    }

    protected function parseBody($body ,$input, $output)
    {
        //check for malformed body
        if (strpos($body, '{') !== false || strpos($body, '}') !== false ){
            $io = new SymfonyStyle($input, $output);
            $io->error(array(
                'Error Processing csv file. Issue contained invalid character, please check your csv syntax',
                $body,
            ));
            return;
        }

        //TODO add flag to convert "1." to github checkboxes? e.g. "1. widget\n" converts to  "- [ ] widget\n"
        //remove trac line breaks and backslashes from description
        $body  =  str_replace('\\\\', "", $body);
        return preg_replace('/\[\[BR\]\]/', "", $body);

    }

    /*
        @param title   string  The title of the issue.
        @param body    string  The contents of the issue.
        @param assignee    string  User that this issue should be assigned to. NOTE: Only users with push access can set the assignee for new issues. The assignee is silently dropped otherwise. This field is deprecated.
        @param milestone   integer The number of the milestone to associate this issue with. NOTE: Only users with push access can set the milestone for new issues. The milestone is silently dropped otherwise.
        @param labels  array of strings    Labels to associate with this issue. NOTE: Only users with push access can set labels for new issues. Labels are silently dropped otherwise.
    */
    protected function buildImportedIssue ($title, $body, $assignee, $milestone, $labels ){
        return [
                "title" => $title,
                "body" => $body,
                "assignee" => $assignee,
                "milestone" => $milestone,
                "labels" => $labels,
        ];
    }

    /*
        @param title   string  The title of the issue.
        @param body    string  The contents of the issue.
        @param assignees    array  User that this issue should be assigned to. NOTE: Only users with push access can set the assignee for new issues. The assignee is silently dropped otherwise. This field is deprecated.
        @param milestone   integer The number of the milestone to associate this issue with. NOTE: Only users with push access can set the milestone for new issues. The milestone is silently dropped otherwise.
        @param labels  array of strings    Labels to associate with this issue. NOTE: Only users with push access can set labels for new issues. Labels are silently dropped otherwise.
    */
    protected function buildNewIssue ($title, $body, $assignees, $milestone, $labels ){
        return [
                "title" => $title,
                "body" => $body,
                "assignee" => $assignees[0],
                "milestone" => $milestone,
                "labels" => $labels,
        ];
    }

    /*
        @param title   string  The title of the issue.
        @param body    string  The contents of the issue.

    */
    protected function buildExistingIssue ($title, $body) {
        $issue = [];
        if ($title){
            $issue["title"] = $title;

        }
        if ($title){
            $issue["body"] = $body;

        }
        return $issue;
    }
}