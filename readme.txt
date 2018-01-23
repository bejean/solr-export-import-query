Exemple de commande pour extraire des requetes d'un log solr

sed -E 's/^(.{23}).+\[(.+)\]  webapp=.+params=\{(.+)\} hits=([0-9]+).+ QTime=([0-9]+)$/\1 \2 \3 \4 \5/' select.log
find -name 'solr.log*' -exec grep '/select' {} \; | sed -E 's/^(.{23}).+\[(.+)\]  webapp=.+params=\{(.+)\} hits=([0-9]+).+ QTime=([0-9]+)$/\1 \2 \3 \4 \5/'