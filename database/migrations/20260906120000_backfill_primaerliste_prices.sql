-- Backfill drinks.price_amount / price_volume_ml from the reviewed Primaerliste.
--
-- Source: Spezitest_Primaerliste_06_09_2026.xlsx, sheet "Spezi Test", column S
-- ("Preis"), workbook SHA-256
-- 39b7d954dc3b39dabe71852d841ac66f02e7564390af55b5cf1eb413cf0ca096 -- the same
-- workbook already recorded in resources/initial-data/refresh-plan.json.
--
-- The product owner confirmed the Primaerliste Preis values are per 0.5 L, so
-- every row is stored as (price_amount, price_volume_ml = 500); the application
-- derives the EUR-per-0.5 L Preis/Leistung basis from that pair
-- (src/Domain/Rating/PriceNormalizer, see docs/DATA_MODEL.md).
--
-- id -> product mapping follows the reviewed refresh-plan.json record order,
-- which is the fixed initial-data seed id order (the 166 Primaerliste products
-- are seed ids 31-196; ids 1-30 are the procurement-only drinks and stay NULL).
-- Verified before writing: all 125 tested rows already carry the identical value
-- in drink_tests.price_amount. Two source values carried 5 decimals (id 141
-- 0.79916, id 161 0.81583) and are rounded half-up to the column's 4-decimal
-- scale -- the same precision the admin price form enforces.
--
-- Single idempotent statement: re-running writes the same values.

UPDATE `drinks`
    SET `price_volume_ml` = 500,
        `price_amount` = CASE `id`
        WHEN 31 THEN 1.65  -- Flensburger Küsten Mix Cola-Orange
        WHEN 32 THEN 0.575  -- Brunnthaler Cola-Mix
        WHEN 33 THEN 0.58  -- Bad Brambacher Cola-Mix
        WHEN 34 THEN 1.26  -- SilberQuelle Cola-Mix
        WHEN 35 THEN 0.6  -- Königs Cola-Mix
        WHEN 36 THEN 0.98  -- RaGGar Dricka ELDFLAMES
        WHEN 37 THEN 0.56  -- Schwip Schwap Zero
        WHEN 38 THEN 0.53  -- Roth's Fruchtflaschl Cola-Mix
        WHEN 39 THEN 0.65  -- Schartner Bombe Spezi
        WHEN 40 THEN 2.22  -- isis Cola Orange
        WHEN 41 THEN 2.23  -- Dreh und Trink Cola-Mix
        WHEN 42 THEN 0.8995  -- Krombacher Spezi
        WHEN 43 THEN 0.76  -- Braumeisters Cola-Mix
        WHEN 44 THEN 0.56  -- Cubana Cola-Mix
        WHEN 45 THEN 0.54  -- Silber Brunnen Cola-Mix
        WHEN 46 THEN 0.48  -- Lidwinen Cola-Mix-Limonade
        WHEN 47 THEN 0.434  -- Berg Quellen ColaMix
        WHEN 48 THEN 0.42  -- Fruchtkistl Cola-Mix
        WHEN 49 THEN 0.36  -- Lieler Früchtchen Cola Mix Limonade
        WHEN 50 THEN 2.27  -- Cola Mix
        WHEN 51 THEN 0.7  -- BODO Cola-Mix
        WHEN 52 THEN 0.65  -- Stötti Cola-Mix
        WHEN 53 THEN 0.85  -- FETZ! Cola-Mix
        WHEN 54 THEN 0.6  -- Stöttner Limonaden Cola-Mix
        WHEN 55 THEN 0.55  -- Fitella Cola Mix
        WHEN 56 THEN 0.55  -- Adldorfer Cola-Mix
        WHEN 57 THEN 0.53  -- ERL Bräu Cola-Mix
        WHEN 58 THEN 0.5  -- Labertaler Spezial Cola-Mix-Limonade
        WHEN 59 THEN 0.47  -- Förstina Sprudel Cola-Mix
        WHEN 60 THEN 0.45  -- Hornecker Cola-Mix
        WHEN 61 THEN 0.32  -- ALASIA Cola-Mix
        WHEN 62 THEN 0.28  -- Schwarzwald Sprudel Cola-Mix
        WHEN 63 THEN 1.19  -- Berliner Jungs Hauptstadt Limonade
        WHEN 64 THEN 0.4475  -- wita
        WHEN 65 THEN 1.96  -- BENNY Kids Colamix
        WHEN 66 THEN 1.59  -- COLA-O
        WHEN 67 THEN 0.22  -- Cola + Orange
        WHEN 68 THEN 1.45  -- dm Bio Cola Mix
        WHEN 69 THEN 0.41  -- Naturpark Quelle Cola-Mix
        WHEN 70 THEN 0.64  -- Hemelinger cola mix
        WHEN 71 THEN 0.7  -- Wittenseer Cola Mix
        WHEN 72 THEN 0.73  -- Ensinger Cola-Mix Limonade
        WHEN 73 THEN 0.36  -- Plöchl Cola Mix
        WHEN 74 THEN 0.31  -- Lugge Cola-Mix
        WHEN 75 THEN 0.42  -- Dreiser Cola-Mix
        WHEN 76 THEN 1.29  -- Cola-Orange Remix Limonade
        WHEN 77 THEN 0.22  -- River Cola-Mix
        WHEN 78 THEN 0.6  -- EuroPerl Cola Mix
        WHEN 79 THEN 0.7  -- Grünten-Perle Cola-Mix
        WHEN 80 THEN 0.75  -- Spessart specht Cola-Mix
        WHEN 81 THEN 1.88  -- Liebharts Bio Limonade Cola Orange
        WHEN 82 THEN 0.65  -- Cola-Mix Wurm
        WHEN 83 THEN 0.83  -- Oechsner Cola Mix
        WHEN 84 THEN 0.69  -- Haller Wildbadquelle Cola Mix Limonade
        WHEN 85 THEN 0.63  -- Bad Brückenauer Cola-Mix
        WHEN 86 THEN 0.69  -- Schloss Cola-Mix
        WHEN 87 THEN 0.75  -- MARTINS COLA-MIX
        WHEN 88 THEN 0.69  -- Pyraser Waldquelle Cola-Mix
        WHEN 89 THEN 1.88  -- Distel Strolch Cola-Mix-Getränk
        WHEN 90 THEN 0.8  -- St. GeorgenBräu Cola-Mix Hauslimonade
        WHEN 91 THEN 1.09  -- Knabe Mix Kola & Orange
        WHEN 92 THEN 0.9  -- Braustübl COLA MIX
        WHEN 93 THEN 0.87  -- Hessebub Cola Mix
        WHEN 94 THEN 0.5  -- Hochwald Cola-Mix
        WHEN 95 THEN 0.6  -- Heylands Cola Mix
        WHEN 96 THEN 1.16  -- Mixery Cola Orange
        WHEN 97 THEN 1.01  -- Faust Cola-Mix
        WHEN 98 THEN 0.45  -- Wiesentaler Mineralbrunnen W Cola-Mix
        WHEN 99 THEN 0.56  -- Zero limit ohne Zucker Cola-Mix
        WHEN 100 THEN 0.6  -- Peterstaler Cola-Mix
        WHEN 101 THEN 0.54  -- FILIPPO Cola-Mix Zero
        WHEN 102 THEN 2.13  -- The Real Cola Orange
        WHEN 103 THEN 1.21  -- Schwarzwald Cola-Mix
        WHEN 104 THEN 0.39  -- alwa COLA MIX
        WHEN 105 THEN 0.9  -- Granini Die Spezial Limo Cola-Orange
        WHEN 106 THEN 0.47  -- Our Essentials Cola-Mix Orange by Amazon
        WHEN 107 THEN 0.91  -- Kurpfalz Bräu cola mix
        WHEN 108 THEN 0.69  -- MIXX
        WHEN 109 THEN 0.6995  -- Flötzinger Cola-Mix
        WHEN 110 THEN 0.724  -- Bazi Cola-Mix
        WHEN 111 THEN 0.6495  -- Andechser Colamix
        WHEN 112 THEN 0.5  -- Kondrauer "MAXL"
        WHEN 113 THEN 1.63  -- MischMasch
        WHEN 114 THEN 0.4  -- Kola-Mix
        WHEN 115 THEN 0.53  -- Teinacher Cola Mix
        WHEN 116 THEN 0.55  -- Alaska Fifty-Fifty
        WHEN 117 THEN 1.69  -- Spezi
        WHEN 118 THEN 0.62  -- Härtsfelder ColaMix
        WHEN 119 THEN 0.645  -- Allgäuer Fifty Fifty
        WHEN 120 THEN 0.8  -- Paulaner Spezi
        WHEN 121 THEN 0.8  -- Afri Cola Mix
        WHEN 122 THEN 0.6495  -- VC Cola-Orangen-Limonade
        WHEN 123 THEN 1.5  -- Der Aufgeweckte
        WHEN 124 THEN 1.2  -- Autenrieder Orange Cola
        WHEN 125 THEN 0.53  -- Maierbräu Cola-Mix
        WHEN 126 THEN 0.4645  -- Flumi Cola-Mix-Limonade
        WHEN 127 THEN 0.5  -- Sonnenland Cola Mix
        WHEN 128 THEN 0.831  -- Adelholzener Primella Cola Mix
        WHEN 129 THEN 0.65  -- Randegger Cola-Mix
        WHEN 130 THEN 0.991  -- Allgäuer Cola Gmisch
        WHEN 131 THEN 1.71  -- I am SPECIAL Orange & Cola Premium Limonade
        WHEN 132 THEN 0.42  -- Bissinger Auerquelle Cola-Mix
        WHEN 133 THEN 1.291  -- Mezzo Mix
        WHEN 134 THEN 1.67  -- Now Bio Black Orange Cola
        WHEN 135 THEN 0.64  -- Böhringer Mix
        WHEN 136 THEN 0.75  -- Fürst Wallerstein Colamix
        WHEN 137 THEN 1.11  -- ZSCH Colamix
        WHEN 138 THEN 0.645  -- Leo Cola Mix
        WHEN 139 THEN 0.69  -- Paderborner Limo Cola-Orange-Mix
        WHEN 140 THEN 0.75  -- Waldi Cola-Mix
        WHEN 141 THEN 0.7992  -- Adelholzener Cola-Mix
        WHEN 142 THEN 0.52  -- Fz Cola Mix
        WHEN 143 THEN 1.64  -- KITZ MIX
        WHEN 144 THEN 0.48  -- Wüteria Cola Mix
        WHEN 145 THEN 0.47  -- bizzl Cola-Mix
        WHEN 146 THEN 0.65  -- Schussenrieder Cola Mix
        WHEN 147 THEN 0.7081  -- Dietenbronner ColaMix
        WHEN 148 THEN 0.42  -- Odenwald Quelle Mix
        WHEN 149 THEN 0.5995  -- Hubauer Cola-Mix Limonade
        WHEN 150 THEN 0.6495  -- Leikeim Cola Mix
        WHEN 151 THEN 0.57  -- Neunspringe Cola Mix
        WHEN 152 THEN 0.59  -- Cubanita Cola Mix
        WHEN 153 THEN 0.675  -- Spezi
        WHEN 154 THEN 0.66  -- Kostbarer Cola-Mix
        WHEN 155 THEN 0.44  -- Finkbeiner Colamix
        WHEN 156 THEN 0.48  -- IsarPerle cola-mix
        WHEN 157 THEN 0.525  -- Frucade Cola-Mix
        WHEN 158 THEN 0.71  -- Aqua Römer Quelle Cola-Mix
        WHEN 159 THEN 0.72  -- MixCola
        WHEN 160 THEN 0.725  -- Schäffler Cola-Mix
        WHEN 161 THEN 0.8158  -- Sinalco Cola-Mix
        WHEN 162 THEN 0.5  -- Spalter Bunte Heimat Cola-Mix
        WHEN 163 THEN 0.665  -- Libella Cola Mix
        WHEN 164 THEN 0.5475  -- Kühbacher Cola-Mix
        WHEN 165 THEN 1  -- MIO MIO Cola+Orange Mische
        WHEN 166 THEN 2.26  -- Black Orange
        WHEN 167 THEN 0.57  -- Haldina ColaMix
        WHEN 168 THEN 0.75  -- colamix Brauerei Gold Ochsen
        WHEN 169 THEN 1.3  -- Calypzo
        WHEN 170 THEN 1.5  -- Stieglitz Limonade Cola-Orange
        WHEN 171 THEN 0.5395  -- UB Cubana Cola-Mix
        WHEN 172 THEN 0.4745  -- Pöllinger Mineralquelle Cola Mix
        WHEN 173 THEN 0.95  -- ZWO Cola-Mix
        WHEN 174 THEN 0.69  -- REMUS COLA-MIX
        WHEN 175 THEN 0.765  -- Mecki-Mix
        WHEN 176 THEN 0.645  -- Krumbach cola mix
        WHEN 177 THEN 0.79  -- MOY Gaudi Cola Mix
        WHEN 178 THEN 0.63  -- Gilbert's Cola-Mix
        WHEN 179 THEN 0.65  -- Vita Cola Mix
        WHEN 180 THEN 1  -- Giesinger Kracherl Cola Mix
        WHEN 181 THEN 5  -- Endlich Cola-Mix
        WHEN 182 THEN 0.6495  -- Kuchlbauer Cola Mix
        WHEN 183 THEN 0.6395  -- Glorietta Cola-Mix
        WHEN 184 THEN 0.5  -- Oettinger Cola-Orange
        WHEN 185 THEN 0.6  -- Göbel Cola-Mixx
        WHEN 186 THEN 0.736  -- Mexi
        WHEN 187 THEN 0.48  -- castello Cola Mix
        WHEN 188 THEN 0.5  -- King's Mix Cola-Mix-Limonade
        WHEN 189 THEN 0.645  -- Orange-Cola
        WHEN 190 THEN 1.52  -- Gerolsteiner Cola-Mix
        WHEN 191 THEN 0.5  -- Bischofshof Cola Mix
        WHEN 192 THEN 0.55  -- ebner Cola-Mix
        WHEN 193 THEN 1.15  -- Veltins Fassbrause Cola-Orange
        WHEN 194 THEN 0.5495  -- Vitaperle Cola-Mix
        WHEN 195 THEN 0.81  -- Krombacher's Fassbrause Cola & Orange
        WHEN 196 THEN 2.05  -- YIPPY Cola Orange
        END
    WHERE `id` BETWEEN 31 AND 196;
