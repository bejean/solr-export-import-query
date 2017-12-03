Exemple de commande pour extraire des requetes d'un log solr

sed -E 's/^(.{23}).+\[(.+)\]  webapp=.+params=.(.+). .+/\1 \2 \3/' select.log
sed -E 's/^(.{23}).+\[(.+)\]  webapp=.+params=\{(.+)\} hits=([0-9]+).+ QTime=([0-9]+)$/\1 \2 \3 \4 \5/' select.log