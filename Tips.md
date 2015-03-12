# Introduction #

While this code is functional and fairly robust, any number of little glitches can cause trouble. Keep the following tips in mind when considering how to use it.

# Tips #

  * Typical data chunks usually end up between 900 and 1100 bytes. When a chunk can be compressed, it usually shrinks to between 200 and 400 bytes. A good rule of thumb is that an N-kilobyte file will be stored in approximately N chunks.
  * Many of the services don't return URLs in simple formats, so the best recourse is to scrape the response HTML. The scraper uses a very simple regular expression, which is liable to break if the output format changes even slightly. While this won't cause a furl operation to fail, it will reduce the number of services available.
  * The operators of the services that furl uses are under no obligation to leave furled data URLs in their system, or to even allow you to continue accessing their system. Remember this, and play nice.