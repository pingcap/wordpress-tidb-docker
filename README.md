# WordPress TiDB Docker

Use the TiDB Serverless Tier cluster as the database for WordPress.

## Prerequisites

- [TiDB Cloud Serverless Tier](https://tidbcloud.com/)
- [Docker](https://www.docker.com/)
- [Docker Compose](https://docs.docker.com/compose/) (If you have installed **Docker Desktop**, **Docker Compose** should already be included)

In the Ubuntu, you can use this script to install the docker and docker compose:

```bash
sudo sh install.sh
```

## Usage

- Clone submodule `git submodule update --init --recursive`.
- Run `cp .env.example .env`.
- Change the properties in `.env` to match your TiDB Serverless Tier cluster information.

  - In Cluster Overview of TiDB Cloud Web Console, click **Connect** button on the top right of the screen.
  - Select **General** in the **Connect With** dropdown list.
  - Copy and paste the connection infomation into the `.env` file. By default, your TiDB Serverless comes with a `test` DB. You could change the value `TIDB_DB_NAME` to the DB you've created in your TiDB Serverless.

- Run `docker compose up -d`.
- That's it! You can now initialize the WordPress site by going to the [localhost page](http://localhost).

## Stop & Clean

To stop and clean up the Docker containers, run `docker compose down`.
