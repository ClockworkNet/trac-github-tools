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


class AddCSVToGithubCommand extends Command
{

    protected $client;
    protected $owner;
    protected $repo;
    protected $user;

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
        $this->client = new \Github\Client();

        //pull owner and repo from owner/repo format
        list($this->owner, $this->repo) = explode('/', $input->getOption('repo'));

        $this->client->authenticate($this->user, $password, \Github\Client::AUTH_HTTP_PASSWORD);

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
                sleep(1);
            }

            //return null or the number of the given milestone
            $milestone_id  =  $this->findMilestoneNumber($issue["milestone"]);
            $labels        =  $this->parseKeywords($issue["keywords"]);
            $assignee      =  ($issue["owner"]=="nobody") ? $this->user : $issue["owner"];
            $title         =  $issue["summary"];

            //todo add flag to update existing issue if it matches one that is already open
            $issue = $this->client->api('issue')->create($this->owner, $this->repo, $this->buildIssue($title, $body, $assignee, $milestone_id, $labels ) );
            echo "Created: " . $issue["url"];
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

    protected function listMilestones( )
    {
        $labels = $this->client->api('issue')->milestones()->all($this->owner, $this->repo);
        return $labels;
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

        //todo dynamically return id
        return null;
    }

    //TODO determine a way to 1 to 1 search an issue based on csv, right now results are fuzzy
    protected function findIssueBySummary($summary)
    {
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
        //remove trac line breaks from dscription
        return preg_replace('/\[\[BR\]\]/', "", $body);

    }

    /*
        @param title   string  The title of the issue.
        @param body    string  The contents of the issue.
        @param assignee    string  Login for the user that this issue should be assigned to. NOTE: Only users with push access can set the assignee for new issues. The assignee is silently dropped otherwise. This field is deprecated.
        @param milestone   integer The number of the milestone to associate this issue with. NOTE: Only users with push access can set the milestone for new issues. The milestone is silently dropped otherwise.
        @param labels  array of strings    Labels to associate with this issue. NOTE: Only users with push access can set labels for new issues. Labels are silently dropped otherwise.
    */
    protected function buildIssue ($title, $body, $assignee, $milestone, $labels ){
        return [
                "title" => $title,
                "body" => $body,
                "assignee" => $assignee,
                "milestone" => $milestone,
                "labels" => $labels,
        ];
    }
}