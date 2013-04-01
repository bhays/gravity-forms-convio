Gravity Forms Convio Add-on
=============================

Version 0.2

WordPress plugin to link Gravity Forms with Convio Surveys through the Convio Open API. Useful for adding constituants or names to email lists (surveys) managed by Convio.

## TODO
* Better handling of various field types from Convio
* Handle missing surveys when feed is already active
* API improvements

## Requirements
* Convio Open API access
* WordPress 3.5
* PHP 5.3
* Gravity Forms 1.5 - [Get a license here](http://benjaminhays.com/gravityforms)

## Installation
1. Install as a regular WordPress plugin
2. Create a form with the appropriate fields for your survey
3. Input Convio Open API credentials at Forms->Settings->Convio
4. Navigate to Forms->Convio to setup limit feeds for the desired quantity fields

## Changelog

### 0.2
* Fix whitespace/HTML issues from Convio question data

### 0.1 
* Initial release

## License
This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation; either version 2 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.

You should have received a copy of the GNU General Public License along with this program; if not, write to:

Free Software Foundation, Inc. 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.