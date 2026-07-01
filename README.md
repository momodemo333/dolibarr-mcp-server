# Dolibarr MCP Server

Serveur MCP (Model Context Protocol) permettant à un LLM ou agent IA d'interagir avec une instance Dolibarr via son API REST.

## Caractéristiques

- **Découverte dynamique** : Explore automatiquement les modules et endpoints via Swagger/OpenAPI
- **19 outils MCP** : CRUD, gestion de documents, workflow commercial, projets/temps, contacts, extrafields
- **Compatible Dolibarr v18-22+** : S'adapte à votre version
- **Modules custom** : Fonctionne avec les modules tiers
- **Double transport** : stdio (CLI) ou HTTP (web)

## Architecture

```
LLM ←→ MCP Server (PHP) ←→ API REST Dolibarr
              ↓
       Swagger Schema (découverte dynamique)
```

## Prérequis

- PHP 8.1+
- Composer
- Une instance Dolibarr avec l'API REST activée
- Une clé API Dolibarr (DOLAPIKEY)

## Installation

```bash
git clone https://github.com/momodemo333/dolibarr-mcp-server.git
cd dolibarr-mcp-server

php8.1 $(which composer) install

cp .env.example .env
# Éditer .env avec vos valeurs
```

## Configuration

### Variables d'environnement

Créez un fichier `.env` à la racine :

```env
DOLIBARR_URL=https://votre-instance-dolibarr.com
DOLIBARR_API_KEY=votre_cle_api
```

### Configuration Claude Desktop

Ajoutez dans la configuration MCP :

**Linux** : `~/.config/claude/claude_desktop_config.json`
**macOS** : `~/Library/Application Support/Claude/claude_desktop_config.json`

```json
{
  "mcpServers": {
    "dolibarr": {
      "command": "php8.1",
      "args": ["/chemin/vers/dolibarr-mcp-server/bin/server.php"],
      "env": {
        "DOLIBARR_URL": "https://votre-instance-dolibarr.com",
        "DOLIBARR_API_KEY": "votre_cle_api"
      }
    }
  }
}
```

### Configuration MCP JSON

Un fichier `.mcp.json.example` est fourni comme modèle pour les clients compatibles MCP. Copiez-le localement et adaptez les chemins/variables sans jamais committer vos credentials.

## Outils disponibles

### CRUD générique (6 outils)

| Outil | Description |
|-------|-------------|
| `dolibarr_api_explorer` | Découvrir modules, endpoints et paramètres |
| `dolibarr_list` | Lister les ressources avec filtres et pagination |
| `dolibarr_get` | Récupérer une ressource par ID |
| `dolibarr_create` | Créer une ressource |
| `dolibarr_update` | Mettre à jour une ressource |
| `dolibarr_delete` | Supprimer une ressource |

### Workflow commercial (3 outils)

| Outil | Description |
|-------|-------------|
| `dolibarr_action` | Exécuter une action (validate, close, reopen...) |
| `dolibarr_add_line` | Ajouter des lignes aux devis, commandes, factures |
| `dolibarr_create_from` | Créer un document depuis un autre (devis → commande → facture) |

### Gestion des contacts (2 outils)

| Outil | Description |
|-------|-------------|
| `dolibarr_link_contact` | Lier/délier un contact à un document (BILLING, SHIPPING, CUSTOMER) |
| `dolibarr_get_contacts` | Lister les contacts liés à un document |

### Projets / temps (1 outil)

| Outil | Description |
|-------|-------------|
| `dolibarr_add_time_spent` | Ajouter une ligne de temps sur une tâche projet via `/tasks/{id}/addtimespent`, avec diagnostic clair si l'API Dolibarr échoue avant traitement |

### Gestion documentaire (5 outils)

| Outil | Description |
|-------|-------------|
| `dolibarr_documents_list` | Lister les documents attachés |
| `dolibarr_documents_upload` | Uploader un document |
| `dolibarr_documents_download` | Télécharger un document |
| `dolibarr_documents_builddoc` | Générer un PDF |
| `dolibarr_documents_delete` | Supprimer un document |

### Extrafields (2 outils)

| Outil | Description |
|-------|-------------|
| `dolibarr_extrafield_update` | Modifier un champ personnalisé |
| `dolibarr_extrafield_delete` | Supprimer un champ personnalisé |

## Modules supportés

`thirdparties`, `contacts`, `products`, `invoices`, `supplierinvoices`, `orders`, `supplierorders`, `proposals`, `categories`, `users`, `projects`, `contracts`, et tous les modules activés dans votre instance.

## Exemples d'utilisation

```
"Explore les modules disponibles dans Dolibarr"
"Liste les 10 dernières factures"
"Crée un devis pour le client X avec 2 lignes"
"Valide la commande 123"
"Génère le PDF de la facture F2501942"
"Lie le contact Dupont à la commande comme contact facturation"
```

## Structure du projet

```
dolibarr-mcp-server/
├── bin/
│   └── server.php                # Point d'entrée MCP (stdio)
├── public/
│   └── index.php                 # Point d'entrée HTTP
├── src/
│   ├── Bootstrap.php             # Chargement env + construction serveur
│   ├── Container.php             # Conteneur DI (PSR-11)
│   ├── Client/
│   │   ├── DolibarrClient.php    # Client HTTP pour l'API Dolibarr
│   │   └── ApiSchemaClient.php   # Parser Swagger/OpenAPI
│   ├── Support/
│   │   └── FieldMapper.php       # Auto-correction des noms de champs
│   └── Tools/
│       ├── CrudTools.php         # CRUD générique (list, get, create, update, delete)
│       ├── ExplorerTools.php     # Découverte API
│       ├── ActionTools.php       # Actions workflow (validate, close...)
│       ├── LineTools.php         # Gestion des lignes + création depuis document
│       ├── ContactTools.php      # Liaison contacts ↔ documents
│       ├── DocumentTools.php     # Gestion documentaire (PDF, upload, download)
│       └── ExtrafieldTools.php   # Champs personnalisés
├── tests/
│   ├── Support/
│   │   └── FieldMapperTest.php
│   └── Tools/
│       ├── CrudToolsTest.php
│       ├── ActionToolsTest.php
│       ├── LineToolsTest.php
│       ├── ContactToolsTest.php
│       ├── DocumentToolsTest.php
│       └── ExtrafieldToolsTest.php
├── .env.example                  # Template variables d'environnement
├── .mcp.json.example             # Template config MCP
├── composer.json
├── phpunit.xml
├── LLM.md                        # Guide complet des outils pour LLM
├── CHANGELOG.md
└── README.md
```

## Tests

```bash
php8.1 vendor/bin/phpunit
```

## Documentation complémentaire

- [LLM.md](LLM.md) — Guide détaillé des outils avec paramètres, exemples et bonnes pratiques
- [CHANGELOG.md](CHANGELOG.md) — Historique des versions

## Compatibilité

| Composant | Version |
|-----------|---------|
| Dolibarr | v18.0+ (testé jusqu'à v22) |
| PHP | 8.1+ |
| MCP Protocol | 2024-11-05 |
| SDK | php-mcp/server ^3.3 |

## License

MIT
