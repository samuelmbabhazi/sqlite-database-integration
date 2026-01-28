I'd like to write a detailed post documenting the whole undertaking of rewriting
a basic SQLite layer (WP_SQLite_Translator in this project) to a much more advanced
MySQL-on-SQLite driver (WP_PDO_MySQL_On_SQLite, WP_SQLite_Information_Schema_Builder,
and other new functionality).

I'm thinking of titles along the lines of:
- WordPress on SQLite
- What does it take to run WordPress on SQLite
- A year of SQLite (since many changes were done in 2025)
- Or similar...

This undertaking includes all of the below implemented in PHP:
- New AST-based parser that can parse ~70k MySQL queries from the MySQL server test suites.
- A new translation layer that supports lots of advanced MySQL functionalities on top of SQLite.
- A new MySQL information schema emulation layer.
- A PDO API implementation for the new driver (close to completion).
- Support for MySQL tools like Adminer and phpMyAdmin.
- Implementation of MySQL binary protocol to support (already working).
- And a lot more, as per the history of this git repository.

I'm including a few posts that I wrote on the topic in this directory. They all
follow the filename format of "post-YYYY-MM-DD.txt".

However, the main sources of all the related details and complexities are:
- All the source files and test cases that correspond to the new driver; especially
  with emphasis on the detailed comments that document all the tricks and complexities
  in WP_PDO_MySQL_On_SQLite and in other classes.
- GitHub releases and related changelogs.
- This repository and its history all the way back to the Nov 18, 2024 when the
  initial PR with the lexer and parser was merged.
- All the pull request since then and comments in them on GitHub. It's almost all
  in https://github.com/WordPress/sqlite-database-integration, but some details
  lie in https://github.com/Automattic/sqlite-database-integration as well, where
  the repository was temporarily forked.

I want you to read and analyze all of these resources, go through the codebase
and the comments, and git history, and GitHub releases and pull requests, and the
related conversations, and the included posts, all of that while following any
linked materials whenever possible. During that research, store the collected
information in files in this directory (state-of-sqlite-post), keeping in mind
that it will then be used to write the post.

Go on meticulously and deeply, browse all the information, and don't stop until
it's all done. You can commit changes to this branch (state-of-sqlite), but NEVER
push anything to the remote, and ONLY commit to this branch.
