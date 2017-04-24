## Load Trac style CSV files as Github Issues

### Clone the repo

### make a csv with issue data
Expected header row is:
`type,owner,status,milestone,keywords,summary,description`


### Run The command 
`./trac-tool import:csv-to-github  --repo=tmulry/IssueLoaderPlayground --user=tmulry` (use ` --help` to see all options)

### Limitations

This script will create duplicate issues of the same title.  I suggest closing duplicate issues before you load.

Github aggressively limits content creation via API when the content creates notifications [https://developer.github.com/guides/best-practices-for-integrators/#dealing-with-abuse-rate-limits].  As a workaround, the import will wait one second between requests.

Issue creation is limited if you don't have push access to repos.   Push access is required to set assignee, labels, and milestone via the API.  Be very sure you know what repo you are using before loading a bunch of issues.
