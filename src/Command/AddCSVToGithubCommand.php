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


class AddCSVToGithubCommand extends Command
{
    protected function configure()
    {
        $this
            // the name of the command (the part after "bin/console")
            ->setName('app:csv-to-github')

            // the short description shown while running "php bin/console list"
            ->setDescription('Imports a csv file to github.')

            // the full command description shown when running the command with
            // the "--help" option
            ->setHelp('This command takes a csv file listing trac issues and adds them to a Github repo')
            ->addOption('columns', '-c',InputOption::VALUE_NONE, 'Show valid csv columns', null)
            ->addOption('repo', null ,InputOption::VALUE_REQUIRED, 'Full owner and repo e.g. "michaelfoods/ops"', null)
            ->addOption('user', null ,InputOption::VALUE_REQUIRED, 'Github username.  must be authorized for selected repo', null)

            ->addArgument('file',InputArgument::REQUIRED, 'csv file to import');
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

        //load file data and parse header
        $csv = new CsvImporter($input->getArgument('file'), true);
        $issue_data = $csv->get();
        //check header for extra columns
        foreach ($csv->getHeader() as $key => $value) {
            if ( !in_array($value, $this->supported_columns) ){
                return;
            }
        }

        $user  =  $input->getOption('user');
        $client = new \Github\Client();

        //todo make inputs for these from tmulry/IssueLoaderPlayground format
        list($owner, $repo) = explode('/', $input->getOption('repo'));

        $client->authenticate($user, $password, \Github\Client::AUTH_HTTP_PASSWORD);

        $repoData = $client->api('repo')->show($owner,$repo);
        if($repoData["private"] === false){
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion('You are loading issues to a public repository, Continue with this action (y/N)? ', false);

            if (!$helper->ask($input, $output, $question)) {
                return;
            };
        }

        if($repoData["permissions"]["push"] === false){
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion('You do not have access to set Assignees, Milestone or Labels, Continue with this action (y/N)? ', false);

            if (!$helper->ask($input, $output, $question)) {
                return;
            };
        }


        $tabular_output = [];
        foreach ($issue_data as $key => $issue) {
            $body          =  $this->parseBody($issue["description"], $input, $output);

            if ($body === null){
                return;
            }
            if ($key > 10){
                //Github cries foul if you try to POST too many issues at once, so slow down after 10 requests
                sleep(1);
            }
            $milestone_id  =  $this->findMilestoneId($issue["milestone"]);
            $labels        =  $this->parseKeywords($issue["keywords"]);
            $owner         =  ($issue["owner"]=="nobody") ? $user : $issue["owner"];
            $title         =  $issue["summary"];
            //todo add flag to update existing issue if it matches one that is already open
            $issue = $client->api('issue')->create($owner, $repo, $this->buildIssue($title, $body, $owner, $milestone_id, $labels ) );

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

    protected function findMilestoneId($name)
    {
        //todo dynamically return id
        return null;
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
        return preg_replace('/\[\[BR\]\]/', "", $body));

    }



            // title   string  Required. The title of the issue.
            // body    string  The contents of the issue.
            // assignee    string  Login for the user that this issue should be assigned to. NOTE: Only users with push access can set the assignee for new issues. The assignee is silently dropped otherwise. This field is deprecated.
            // milestone   integer The number of the milestone to associate this issue with. NOTE: Only users with push access can set the milestone for new issues. The milestone is silently dropped otherwise.
            // labels  array of strings    Labels to associate with this issue. NOTE: Only users with push access can set labels for new issues. Labels are silently dropped otherwise.

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