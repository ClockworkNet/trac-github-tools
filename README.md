## Load Trac style CSV files as Github Issues

### Clone the repo

### make a csv with issue data
Expected header row is:
`type,owner,status,milestone,keywords,summary,description`

`type` and `status` are placeholders, and will be ignored.
`owner` - this is the desired github user of the assignee.  It will only be added if the requested assignee is a collaborator on the project.
`milestone` - this must match the name of an existing milestone in the project
`keywords` - Keywords can be comma separated, surronded in brackets.  They will be set to labels (e.g. `[KW1,KW2]` will put the labels of "KW1" and "KW2" on the issue )
`summary` - this maps directly to the 'title' or headline on the issue.  If the summary matches an existing open issue, it will Update that issue
`description` - Markdown string describing the body of the issue

### Run The command
`./trac-tool import:csv-to-github  --repo=tmulry/IssueLoaderPlayground --user=tmulry test.csv` (use ` --help` to see all options)

### Limitations

This script will create duplicate issues of the same title.  I suggest closing duplicate issues before you load.

Github aggressively limits content creation via API when the content creates notifications [https://developer.github.com/guides/best-practices-for-integrators/#dealing-with-abuse-rate-limits].  As a workaround, the import will wait one second between requests.

Issue creation is limited if you don't have push access to repos.   Push access is required to set assignee, labels, and milestone via the API.  Be very sure you know what repo you are using before loading a bunch of issues.
