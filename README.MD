Twitter Timeline
================
####version 0.1

A basic tool that loads tweets for a specified user. Tweets are cached for a set period of time before newer tweets are retrieved.

For quick asthetics Foundation 5 was used.

This project was intended as a quick experiment to become accustom with the Twitter API. So rather than use a library I wrote my own way of accessing the twitter API, which is by no means perfect but it is flexible code for retrieving timeline data.

To make things a little more interesting this little app caches tweets in the db for 10 minutes (although this is easily changed) per user. The next request after 10 minutes will request new tweets since the user's last cached tweet.

I have written a TODO section in this document to highlight the known shortcomings that I am aware of if I ever come back and decide to clean up this app.

Features
--------
* Tweets are cached per user, via mysql tables.
* Twitter API implemenation supports additional parameters on timeline requests if required.
* Search other user tweets through the textfield.

Installation
------------
Run ``db/twitter_timeline_schema.sql`` on a mysql database to setup the schema.

Rename ``oauth_settings.php.example`` to ``oauth_settings.php`` and fill in the appropriate oauth values from your twitter api developer account.

Check ``class QuickConfiguration`` and edit db default parameters, or specify parameters where ``QuickConfiguration`` is called in the ``Controller`` section of the code.

TODO
----

* Better exception handling and parameter checking.

* Externalize classes, and possibly use a lightweight MVC framework.

* Support one cleanly setup configuration file for database connections and twitter api oauth values.

* Move app-specific styling and js to respective folders.

* PHPDoc the thing.