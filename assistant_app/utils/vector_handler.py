import hashlib
import logging
from pathlib import Path

from django.conf import settings

logger = logging.getLogger(__name__)


class VectorStoreUnavailable(RuntimeError):
    pass


class RockfordVectorStore:
    def __init__(self):
        try:
            import chromadb
            from chromadb.utils import embedding_functions
        except ImportError as exc:
            raise VectorStoreUnavailable(
                "chromadb is not installed; vector search is disabled."
            ) from exc

        db_path = getattr(
            settings,
            "AI_ASSISTANT_VECTOR_DB_PATH",
            Path(settings.BASE_DIR) / "rockford_vector_db",
        )
        collection_name = getattr(
            settings,
            "AI_ASSISTANT_VECTOR_COLLECTION",
            "rockford_knowledge",
        )

        self.db_path = Path(db_path)
        self.db_path.mkdir(parents=True, exist_ok=True)
        self.chroma_client = chromadb.PersistentClient(path=str(self.db_path))
        self.collection = self.chroma_client.get_or_create_collection(
            name=collection_name,
            embedding_function=embedding_functions.DefaultEmbeddingFunction(),
        )

    @staticmethod
    def _chunks(content, chunk_size=1200, overlap=150):
        text = str(content or "").strip()
        if not text:
            return []

        chunk_size = max(int(chunk_size), 200)
        overlap = min(max(int(overlap), 0), chunk_size - 1)
        step = chunk_size - overlap
        return [
            text[start : start + chunk_size]
            for start in range(0, len(text), step)
            if text[start : start + chunk_size].strip()
        ]

    def inject_knowledge_base(
        self,
        extracted_data,
        namespace="deep_crawler",
        replace_namespace=False,
    ):
        namespace = str(namespace or "default")
        if replace_namespace:
            self.collection.delete(where={"namespace": namespace})

        ids = []
        documents = []
        metadatas = []
        chunk_size = getattr(settings, "AI_ASSISTANT_VECTOR_CHUNK_SIZE", 1200)
        overlap = getattr(settings, "AI_ASSISTANT_VECTOR_CHUNK_OVERLAP", 150)

        for item in extracted_data:
            content = str(item.get("content", "")).strip()
            source = str(item.get("source", "")).strip()
            content_type = str(item.get("type", "document")).strip() or "document"

            for index, chunk in enumerate(
                self._chunks(content, chunk_size=chunk_size, overlap=overlap)
            ):
                digest = hashlib.sha256(
                    f"{namespace}\0{source}\0{content_type}\0{index}\0{chunk}".encode(
                        "utf-8"
                    )
                ).hexdigest()
                ids.append(digest)
                documents.append(chunk)
                metadatas.append(
                    {
                        "namespace": namespace,
                        "source": source,
                        "type": content_type,
                    }
                )

        batch_size = 200
        for start in range(0, len(documents), batch_size):
            end = start + batch_size
            self.collection.upsert(
                ids=ids[start:end],
                documents=documents[start:end],
                metadatas=metadatas[start:end],
            )

        logger.info(
            "Indexed %s text chunks in vector namespace %s.",
            len(documents),
            namespace,
        )
        return len(documents)

    def query_relevant_knowledge(self, user_query, n_results=4):
        query = str(user_query or "").strip()
        collection_count = self.collection.count()
        if not query or collection_count < 1:
            return ""

        result_count = min(max(int(n_results), 1), collection_count)
        try:
            results = self.collection.query(
                query_texts=[query],
                n_results=result_count,
                include=["documents", "metadatas"],
            )
        except Exception:
            logger.exception("Vector knowledge query failed.")
            return ""

        documents = (results or {}).get("documents") or []
        metadatas = (results or {}).get("metadatas") or []
        context = []

        for group_index, document_group in enumerate(documents):
            metadata_group = (
                metadatas[group_index] if group_index < len(metadatas) else []
            )
            for document_index, document in enumerate(document_group):
                metadata = (
                    metadata_group[document_index]
                    if document_index < len(metadata_group)
                    else {}
                )
                source = str((metadata or {}).get("source", "")).strip()
                prefix = f"Source: {source}\n" if source else ""
                context.append(prefix + str(document))

        return "\n\n---\n\n".join(context)
