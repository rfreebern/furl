# What is this? #

URL shortening web sites such as tinyurl.com, bit.ly, is.gd etc. provide a service that takes an arbitrary string and (effectively) losslessly compresses it to a much shorter one. By encoding data in such a way that it can be parsed as a valid URL, we can store any data we desire as short URLs.

The name _furl_ is a conflation of _file_ and _URL_, and also conjures the image of rolling something up into a smaller, more compact package.

This is less a coherent project than simple a collection of code that implements various similar versions of the concept. If you're intrigued and want to write your own, please feel free to contribute.

## Why? ##

URL shorteners are [considered harmful](http://joshua.schachter.org/2009/04/on-url-shorteners.html) to the health of the web as a whole. Instead of ignoring this harm and using them as expected, we can make use of their functionality for other purposes. Furl provides an alternate use that is potentially beneficial to end users and doesn't harm the web, because the resulting links won't be used as redirects.

## But isn't this _wrong?_ ##

If the URL shorteners have a problem with people taking perfectly valid long URLs and converting them to short URLs, perhaps they should reconsider the service they are providing.

## This has happened before... ##

I'm not the first person with this idea. Back in 2005, someone [built an entire filesystem that stored data on tinyurl.com](http://tech.slashdot.org/article.pl?sid=05/10/25/0350222), and just recently [Mario Vilas implemented a similar idea in python](http://breakingcode.wordpress.com/2010/01/14/having-fun-with-url-shorteners-part-2-parasitic-storage/). (Thanks to Mario for the excellent term "parasitic storage".)

## And it will happen again ##

In November 2010, [Neal Poole](http://nealpoole.com/) wrote a furl-like system using the bit.ly URL shortening service. [His code is available on github](https://github.com/nealpoole/hackabit-projects/tree/master/bitly-file-storage/).

On April 19th, 2011, [David Chambers](http://twitter.com/davidchambers) launched [hashify.me](http://hashify.me/unpack:fMdNSb,dSAsEI,gl6dvQ), a service to encode arbitrary-length documents in URLs.

## Usage ##

Check out the source from the subversion repository (see the [source tab](http://code.google.com/p/furl/source/checkout)), change to the _php_ directory, then type

```
./furl some_file.txt
```

to store some\_file.txt as a short URL, which will be printed to the console. Type

```
./unfurl http://the.link/abc123
```

to retrieve the data stored at that link.

To use the python code, please read [Mario Vilas' post](http://breakingcode.wordpress.com/2010/01/14/having-fun-with-url-shorteners-part-2-parasitic-storage/) about it.

For testing, the poem _Jabberwocky_ by Lewis Carroll is furled at the following URLs:

  * http://is.gd/8319t
  * http://urlsp.in/f14408
  * http://is.gd/831zL

## Bookmarklet ##

If you're viewing a page with furl data in its URL, you can use this bookmarklet to extract some information from the furl data. If it's an index, it'll tell you the filename, the MD5 sum, and the URLs of the data chunks. If it's raw data, it'll display it (which might look bizarre). **UPDATE:** I've incorporated [Masanao Izumo](http://www.onicos.com/staff/iz/amuse/javascript/expert/inflate.txt)'s inflate javascript so that you can attempt to view raw uncompressed data, which (again) might just look like a mess if it's not text.

&lt;wiki:gadget url="http://furl.googlecode.com/svn/trunk/misc/gadget.xml" border="0" height="15" /&gt;

To use it, right-click the "Furl Info" link above and bookmark it, then visit a furl URL and click the bookmarklet. Make sure the URL in your browser's address bar is the actual furl URL; some URL shorteners use a frame.

The bookmarklet is far from perfect. Occasionally it takes a couple tries before it actually unfurls the data (due to latency loading the inflate script), and sometimes the resulting div won't dismiss when you click it.