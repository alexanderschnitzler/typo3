..  include:: /Includes.rst.txt

..  _important-T06-1-1790355249:

===========================================================================
Important: #T06-1 - Extbase query settings fix application type on creation
===========================================================================

See :issue:`T06-1`

Description
===========

Whether an Extbase query applies frontend or backend rules to enable
fields (:php:`hidden`, :php:`starttime`/:php:`endtime`, workspace state
and the like) is now decided when the query settings are created, not
when the query is executed.

:php:`\TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings` reads
the global request once, in its constructor, and stores the result.
Building the query's SQL later reads that stored decision instead of
reading the global request again.

Code that creates a :php:`Typo3QuerySettings` (directly, or indirectly
through a :php:`Query`) during one application type and executes that
query after the global request has changed to the other application
type is affected: a query built during a frontend request now keeps
frontend enable-field rules even if it executes after the request
turned into a backend one, and the reverse also holds. Before this
change, the rules in effect at execution time decided.

This mainly matters for code that builds a query in one context and
defers its execution into another, for example across a sub-request.
Code that creates and executes a query within the same request is not
affected.

:php:`\TYPO3\CMS\Extbase\Persistence\Generic\QuerySettingsInterface`
itself is unchanged. Custom implementations of that interface do not
carry the decision and keep falling back to a check of the global
request at execution time, exactly as all query settings did before
this change.

..  index:: PHP-API, ext:extbase
