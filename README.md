#Introduction

this is a nice little php script that you can run in the background via cron to constantly check your mail servers (or any server you have that deals with mail) and alert you via slack

I know there are things out there like mxtoolbox that can do this for you, and include a larger list of blacklists, but I wanted something I could run on our own infrastructure that did what I wanted, and alerted me via slack (much cooler then email alerts)

#Usage

To use this you will need
* A slack API token
* A slack webhook to the channel you want to post to
* PHP (of course)

1. Download this repo 
2. open `blacklist-cron.php` and edit settings at the top of the script to include slack specific stuff
3. edit `servers.txt` to add in your mail servers (can be IP or domain name)
4. edit `dnsbls.txt` to include all the DNSBL servers you want to check against (left this full of the ones I use)
  * be sure to check each one. Some, like baracuda, require you to sign up if you are going to be making lots of calls
5. Make a cron entry, like `00 08-18 * * * /usr/bin/php /home/john/blacklist/blacklist-cron.php >> /home/john/blacklist/cron.log`
  * This will run the script every hour between 8am and 6pm (office ours). I also did this to reduce the amount of requests i send to each dnsbl. You can change this to suit your needs

![slack notification](https://raw.githubusercontent.com/mrwhale/blacklist-checker/master/slack-blacklist-checker.png)

You will now be alerted when a server of yours gets put on any of those blacklists, like this. It will also alert you when they are removed again

Hope this is useful for you
