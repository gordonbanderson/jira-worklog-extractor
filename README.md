# JIRA Worklog Extractor

Tool to help extract worklogs from JIRA. The native UI and REST interface for dealing with worklogs is a bit clunky so I wrote
this little tool to help with it.

Currently outputs a CSV table with the projects on the rows, authors on the columns and worked hours in the cells.

# Usage

```
composer require jpastoor/jira-worklog-extractor dev-master@dev
```

Copy the config.json.template to config.json and alter the values.

To run it:
````bash
php app.php load-project-totals 2016-01-01 2016-03-31
````

```bash
php app.php worked-hours-per-day-per-author-per-ticket 2017-07-01 2017-07-11
```

Example command:
````bash
php app.php load-project-totals 2016-01-01 2016-03-31
````

Example output:
````bash
project;matthijs;jeroen;chris;ernst;joost
WGF;52;0;1;20;7
WATSAFEGBS;0;0;9;0;0
WAT;119;0;0;7;39
````


# License

MIT License

# Thanks to

Marius Storm-Olsen for his code sample on https://answers.atlassian.com/questions/87961/how-to-get-list-of-worklogs-through-jira-rest-api
