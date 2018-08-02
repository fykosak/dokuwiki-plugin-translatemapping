## Language data page example
````
  * cs start en english_title_page                          First line is special, contains title pages
  * cs rocnik* en year*                                     You can use wildcard asterisk
    * cs ulohy en problems, cs vysledky en results          Multiple translations are sepatared by ", "
      * cs serie* en series*                                Each level is two spaces deeper
    * cs vaf en wap
    * cs fyziklani en physicsbrawl
  * cs o-nas en about-us
````

If you install this plugin manually, make sure it is installed in
lib/plugins/translatemapping/ - if the folder is called different it
will not work!

Please refer to http://www.dokuwiki.org/plugins for additional info
on how to install plugins in DokuWiki.

----
Copyright (C) Štěpán Stenchlák <s.stenchlak@gmail.com>

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; version 2 of the License

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

See the LICENSING file for details