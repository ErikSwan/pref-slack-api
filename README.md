# PSEF Slack Bot/Slash Command API

This is provided without documentation. It's pretty simple. If you have questions about how it works (particularly the auth stuff), consult the offical Slack API documentation and the Google Calendar API documentation (you will need to follow Google's calendar API example in particular to get the auth setup correctly).

One note: 
`index.php` is the main endpoint for the slash command.
`reminder-bot.php` is the file that hooks into Slackbot to automatically DM reminders to users. This is something that you would want to put on a cron job every half hour or hour.
