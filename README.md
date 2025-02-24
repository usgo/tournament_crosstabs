## Development Setup

1. Create a MySQL database and import sql/create.sql
2. Create header and footer html pages
3. Make the sgf folder writable by the web server (or just chmod 777)
4. Copy conf.php.default to conf.php
5. Update conf.php with appropriate values
6. Customize phtml pages
7. Go to the admin section to create bands, players, and rounds

## Contributions
We welcome contributions to the Tournament Crosstabs application. Contributions can be by creating
an issue, helping out with an issue or submitting a pull request.

## How to Setup a New Tournament
1. Login to admin site localhost/admin  
2. Create new Band [band=tournament]  
    1. Add Band  
    2. Do not add players  
3. Create Rounds  
    1. Add Round  
    2. Select band from list, newest is on the bottom  
    3. Type rounds in order from 1-X where X is the max number of rounds  
    4. Do not add dates for rounds  
4. Add players and round data  
    1. Import Results  
    2. Select Band from list  
    3. Select file type from dropdown list  
       - PyTD JSON - JSON file generated from newer version of PyTD  
       - PyTD XML - This is the XML that can be generated from PyTD Old, OpenGotha, Jon Boley TD program  
       - AGA Results TXT - This is the parsed txt export that is sent to the AGA for final ratings  
    4. Import file will build the player list and generate the results for the crosstabs.   
    5. Check the tournament you’ve imported for the correct results  
5. Managing Game Results / Adding SGF to game records  
    1. Manage Game Results  
    2. Search for the players names and check for the date  
    3. Click the result of the matchup  
    4. Select SGF file from file chooser  
    5. Click submit to add the SGF  
    6. Check tournament to make sure files are added correctly  

If there are any issues, contact Steve (steve.colburn@usgo.org) for assistance. Help request must include Band, Round, Players, and Actual result. This happens once per congress and can only be fixed through the database directly.
