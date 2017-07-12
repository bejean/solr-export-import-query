# Solr export, import and query load tools

## solr-export.php
Export Solr core or collection content in set of json files. For a document, all stored fields are exported. The purpose of this script is to provide you datas for the solr-import.php script. Obviously, if all fields are not stored it won't really help (in Solr configure all fields as stored unecessarilly is not a good practice).

It is possible to specify
  - solr url
  - core or collection name
  - json file size (number of documents)
  - output directory
  - solr q and/or fq parameters
  - fields to be ignored (generally fields you do not need to import due to copyfield directives)

## solr-import.php
Import json files into Solr core or collection.

It is possible to specify
  - solr url
  - core or collection name
  - input directory
  - commit after each file or not
  - final commit or not
  - final optimize or not

In order to simulate real Solr life, the script executes loops. For loops, you can specify 
  - loop duration
  - loop import duration

For instance with loop duration set to 300 seconds and loop import duration set to 120 seconds, documents are imported during 120 seconds and a pause occurs during 180 seconds.

## solr-query.php
Perform queries into Solr core or collection based on solr log

It is possible to specify
  - solr url
  - core or collection name
  - log directory
  - log filename pattern

In order to simulate real Solr life, the script executes loops. For loops, you can specify 
  - loop duration
  - loop query duration

For instance with loop duration set to 300 seconds and loop query duration set to 120 seconds, queries are posted during 120 seconds and a pause occurs during 180 seconds.

in order to avoid reading not relevant lines in Solr log files, build a dedicated log file with grep 
```
$ grep -h 'INFO' solr.log* | grep 'collection_name' | grep 'path=/select' > queries.log
```

## Usage 

All scripts accept 2 optionnal parameters

```
-i configuration_file. Default : solr-export. ini (or solr-import.ini or solr-query.ini)
-c collection_name. Default : the collection name configurated in configuration file

php solr-export.php -i config.ini -c customer 
```

# Notes

  - Import and Query scripts are not multi-threaded. 
In order to perform concurrent updates in a same core or collection or perform updates in several cores or collection simulteanouslly, you need to launch several solr-import.php script instances (in several terminals).
In order to perform concurrent queries in a same core or collection or perform queries in several cores or collection simulteanouslly, you need to launch several solr-query.php script instances (in several terminals).
  - A best practice is to not execute the script on the Solr server in order to not use CPU, memory and disk I/O on the Solr server. Use one or several remote client computers. 
  - If you want to simulate a high Solr load, you will need to launch a lot of script instances. In order to not make your client computer the bottleneck, you will have to use several client computers. 

# Todos

  - add more logs
  - report update and query performances statistics
