[![Latest Stable Version](https://poser.pugx.org/thecodingmachine/tdbm-graphql/v/stable)](https://packagist.org/packages/thecodingmachine/tdbm-graphql)
[![Total Downloads](https://poser.pugx.org/thecodingmachine/tdbm-graphql/downloads)](https://packagist.org/packages/thecodingmachine/tdbm-graphql)
[![Latest Unstable Version](https://poser.pugx.org/thecodingmachine/tdbm-graphql/v/unstable)](https://packagist.org/packages/thecodingmachine/tdbm-graphql)
[![License](https://poser.pugx.org/thecodingmachine/tdbm-graphql/license)](https://packagist.org/packages/thecodingmachine/tdbm-graphql)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/thecodingmachine/tdbm-graphql/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/thecodingmachine/tdbm-graphql/?branch=master)
[![Build Status](https://travis-ci.org/thecodingmachine/tdbm-graphql.svg?branch=master)](https://travis-ci.org/thecodingmachine/tdbm-graphql)
[![Coverage Status](https://coveralls.io/repos/thecodingmachine/tdbm-graphql/badge.svg?branch=master&service=github)](https://coveralls.io/github/thecodingmachine/tdbm-graphql?branch=master)


TDBM-GraphQL
============

**Work in progress, no stable release yet**

GraphQL bridge between TDBM and Youshido/graphql library.

This library will generate GraphQL types based on your database model.


Troubleshooting
---------------

### Error: Maximum function nesting level of '100' reached

Youshido's GraphQL library tends to use a very deep stack. This error does not necessarily mean your code is going into an infinite loop.
Simply try to increase the maximum allowed nesting level in your XDebug conf:

```
xdebug.max_nesting_level=500
```
