<?php


namespace Jpastoor\JiraWorklogExtractor\Command;

use chobie\Jira\Api;
use chobie\Jira\Issue;
use Jpastoor\JiraWorklogExtractor\CachedHttpClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use XLSXWriter;

/**
 * Class WorkedHoursPerDayPerAuthorPerTicketCommand
 *
 * Days on the rows
 * - columns: authors
 *
 * @package Jpastoor\JiraWorklogExtractor
 * @author Joost Pastoor <joost.pastoor@munisense.com>
 * @copyright Copyright (c) 2016, Munisense BV
 */
class WorkedHoursPerDayPerAuthorPerTicketCommand extends Command
{
    const MAX_ISSUES_PER_QUERY = 100;

    protected function configure()
    {
        $this
            ->setName('worked-hours-per-day-per-author-per-ticket')
            ->setDescription('Days on the rows, labels on the columns and different tabs per person')
            ->addArgument(
                'start_time',
                InputArgument::REQUIRED,
                'From when do you want to load the worklog totals (YYYY-mm-dd)'
            )
            ->addArgument(
                'end_time',
                InputArgument::OPTIONAL,
                'End time to load the worklog totals (YYYY-mm-dd)',
                date("Y-m-d")
            )->addOption(
                'clear_cache', "c",
                InputOption::VALUE_NONE,
                'Whether or not to clear the cache before starting'
            )->addOption(
                'output-file', "o",
                InputOption::VALUE_REQUIRED,
                'Path to Excel file',
                __DIR__ . "/../../output/output_" . date("YmdHis") . ".xlsx"
            )->addOption(
                'authors-whitelist', null,
                InputOption::VALUE_OPTIONAL,
                'Whitelist of authors (comma separated)'
            )->addOption(
                'labels-whitelist', null,
                InputOption::VALUE_OPTIONAL,
                'Whitelist of labels (comma separated)'
            )->addOption(
                'config-file', null,
                InputOption::VALUE_OPTIONAL,
                'Path to config file',
                __DIR__ . "/../../config.json"
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $start_time = $input->getArgument('start_time');
        $end_time = $input->getArgument('end_time');
        $start_time_obj = \DateTime::createFromFormat("Y-m-d", $start_time);
        $end_time_obj = \DateTime::createFromFormat("Y-m-d", $end_time);
        $start_timestamp = mktime(0, 0, 0, $start_time_obj->format("m"), $start_time_obj->format("d"), $start_time_obj->format("Y"));
        $end_timestamp = mktime(23, 59, 59, $end_time_obj->format("m"), $end_time_obj->format("d"), $end_time_obj->format("Y"));

        if (!file_exists($input->getOption("config-file"))) {
            $output->writeln("<error>Could not find config file at " . $input->getOption("config-file") . "</error>");
            die();
        }

        $config = json_decode(file_get_contents($input->getOption("config-file")));

        $cached_client = new CachedHttpClient(new Api\Client\CurlClient());
        $jira = new Api(
            $config->jira->endpoint,
            new Api\Authentication\Basic($config->jira->user, $config->jira->password),
            $cached_client
        );

        if ($input->getOption("clear_cache")) {
            $cached_client->clear();
        }

        $progress = null;
        $offset = 0;

        $worked_time = [];

        do {

            $jql = "worklogDate <= " . $end_time . " and worklogDate >= " . $start_time . " and timespent > 0  and timeSpent < " . rand(1000000, 9000000) . " ";

            if ($input->getOption("labels-whitelist")) {
                $jql .= " and labels in (" . $input->getOption("labels-whitelist") . ")";
            }

            if ($input->getOption("authors-whitelist")) {
                $jql .= " and worklogAuthor in (" . $input->getOption("authors-whitelist") . ")";
            }

            $search_result = $jira->search($jql, $offset, self::MAX_ISSUES_PER_QUERY, "key,project,labels");

            if ($progress == null) {
                /** @var ProgressBar $progress */
                $progress = new ProgressBar($output, $search_result->getTotal());
                $progress->start();
            }

            // For each issue in the result, fetch the full worklog
            $issues = $search_result->getIssues();
            /** @var Issue $issue */
            foreach ($issues as $issue) {

                error_log('ISSUE:' . print_r($issue, 1));

                $worklog_result = $jira->getWorklogs($issue->getKey(), []);

                $worklog_array = $worklog_result->getResult();
                if (isset($worklog_array["worklogs"]) && !empty($worklog_array["worklogs"])) {
                    foreach ($worklog_array["worklogs"] as $entry) {
                        $author = $entry["author"]["key"];

                        error_log('WORKLOG ENTRY: ' . print_r($entry, 1));

                        // Filter on author
                        if ($input->getOption("authors-whitelist")) {
                            $authors_whitelist = explode(",", $input->getOption("authors-whitelist"));
                            if (!in_array($author, $authors_whitelist)) {
                                continue;
                            }
                        }

                        // Filter on time
                        $worklog_date = \DateTime::createFromFormat("Y-m-d", substr($entry['started'], 0, 10));
                        $worklog_timestamp = $worklog_date->getTimestamp();

                        // @todo Check if correct assumption, and also timezones
                        if ($worklog_timestamp < $start_timestamp || $worklog_timestamp > $end_timestamp) {
                            continue;
                        }

                        error_log('ISSUE ID: ' . $entry['issueId']);
                        error_log('COMMENT: ' . $entry['comment']);


                        @$worked_time[$author][$worklog_date->format("Y-m-d")][$issue->getKey()]['total_time'] += $entry["timeSpentSeconds"] / 60;
                        $newLogEntry = [
                          'time' =>  $entry["timeSpentSeconds"] / 60,
                          'comment' => $entry['comment']
                        ];
                        @$worked_time[$author][$worklog_date->format("Y-m-d")][$issue->getKey()]['entries'][] = $newLogEntry;
                    }
                }
                $progress->advance();
            }

            $offset += count($issues);
        } while ($search_result && $offset < $search_result->getTotal());

        $progress->finish();
        $progress->clear();


        error_log('WORKED TIME: ' . print_r($worked_time, 1));

        if (empty($worked_time)) {
            throw new \Exception("No matching issues found");
        }

        $writer = new XLSXWriter();
        $writer->setAuthor("Munisense BV");

        ksort($worked_time);

        // Fill in empty days
        // @todo check for side fx
        foreach($worked_time as $author => $worked_time_per_author) {
            $current_day_start = $start_timestamp;
            while ($current_day_start <= $end_timestamp) {
                $date = date("Y-m-d", $current_day_start);
                if(!isset($worked_time[$author][$date])) {
                    $worked_time[$author][$date] = 0;
                }
                $current_day_start += 86400;
            }
        }

        list($sheet_headers, $sheet_data_by_date) = $this->convertWorkedTimeOfLabelToSheetFormat($worked_time);

        $writer->writeSheetHeader("sheet1", $sheet_headers);

        $totals_row = [""];
        for ($i = 1; $i < count($sheet_headers); $i++) {
            $totals_row[] = "=ROUND(SUM(" . XLSXWriter::xlsCell(2, $i) . ":" . XLSXWriter::xlsCell(10000, $i) . ")/60,0)";
        }
        $writer->writeSheetRow("sheet1", $totals_row);

        foreach ($sheet_data_by_date as $row) {
            $writer->writeSheetRow("sheet1", $row);
        }


        $writer->writeToFile($input->getOption("output-file"));
    }

    /**
     * @param $worked_time_label
     *
     * @return array
     */
    protected function convertWorkedTimeOfLabelToSheetFormat($worked_time_label)
    {
        // Find unique authors per label
        $unique_authors = array_keys($worked_time_label);

        error_log('UNIQUE AUTHORS: ' . print_r($unique_authors,1));
        
        $sheet_headers = ["Date" => "date", 'Ticket' => 'string', 'Time' => 'integer', 'Ticket Time' => 'string', 'Daily Time' => 'string', 'Comment' => 'string'];
        foreach ($unique_authors as $unique_author) {
            $sheet_headers[$unique_author] = "integer";
        }
        $unique_authors_map = array_flip($unique_authors);

        $sheet_data_by_date = [];
        foreach ($worked_time_label as $author => $worked_time_days_of_author) {
            // error_log('T1 ' . print_r($worked_time_days_of_author, 1));

            /*
             * [GT-2182] => Array
        (
            [total_time] => 35
            [entries] => Array
                (
                    [0] => Array
                        (
                            [time] => 30
                            [comment] => Fixed vendor filter on deliveries report
                        )

                    [1] => Array
                        (
                            [time] => 5
                            [comment] => Confirmed working on develop
                        )

                )

        )

             */

            ksort($worked_time_days_of_author);

            foreach ($worked_time_days_of_author as $date => $worklogEntries) {
                error_log('T2 ' . print_r($worklogEntries, 1));
                $sheet_data_by_date[] = [$date, null,null,null];
                $timePerDay = 0;
                if (!empty($worklogEntries)) {
                    $tickets = array_keys($worklogEntries);
                    error_log('Tickets ' . print_r($tickets, 1));
                    foreach($tickets as $ticket) {
                        error_log('TICKET!!! ' . $ticket);
                        $details = $worklogEntries[$ticket];
                        $entries = $details['entries'];
                        foreach($entries as $entry) {
                            $time = $entry['time'];
                            $comment = $entry['comment'];
                            $sheet_data_by_date[] = [null, $ticket,$time, null, null,  $comment];
                        }

                        $sheet_data_by_date[] = [null, null, null, $details['total_time'], null, null];
                        $timePerDay += $details['total_time'];
                        error_log('TOTAL TIME: ' . $details['total_time']);
                        $sheet_data_by_date[] = [null, null, null, null, null, null];
                    }
                }

                $sheet_data_by_date[] = [null, null, null, null, $timePerDay, null];
                $sheet_data_by_date[] = [null, null, null, null, null, null];
                $sheet_data_by_date[] = [null, null, null, null, null, null];

            }
        }

        ksort($sheet_data_by_date);


       // $sheet_data_by_date['2017-07-06'] = [null,2];
        error_log(print_r($sheet_data_by_date, 1));

        return [$sheet_headers, $sheet_data_by_date];
    }
}
