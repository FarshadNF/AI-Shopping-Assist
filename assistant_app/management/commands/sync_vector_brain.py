from django.core.management.base import BaseCommand, CommandError

from assistant_app.utils.deep_crawler import run_pipeline


class Command(BaseCommand):
    help = "Crawl the configured OpenCart store and rebuild the Vector Brain index."

    def add_arguments(self, parser):
        parser.add_argument(
            "--force",
            action="store_true",
            help="Discard crawler URL/content caches and fetch everything again.",
        )

    def handle(self, *args, **options):
        try:
            result = run_pipeline(force_refresh=options["force"])
        except Exception as exc:
            raise CommandError(str(exc)) from exc

        self.stdout.write(
            self.style.SUCCESS(
                "Vector Brain synchronized: "
                f"{result['products']} products, "
                f"{result['sources']} sources, "
                f"{result['chunks']} chunks."
            )
        )
