import os
import chromadb
from chromadb.utils import embedding_functions
from django.conf import settings

class RockfordVectorStore:
    def __init__(self):
        # تعریف مسیر ذخیره‌سازی محلی دیتابیس برداری در روت پروژه شما
        self.db_path = os.path.join(settings.BASE_DIR, "rockford_vector_db")
        
        # استفاده از تنظیمات کلید پروژه‌تان برای تولید امبدینگ استاندارد
        self.openai_ef = embedding_functions.OpenAIEmbeddingFunction(
            api_key=getattr(settings, "OPENAI_API_KEY", ""),
            model_name="text-embedding-3-small"
        )
        
        # راه‌اندازی کلاینت کروما دی‌بی
        self.chroma_client = chromadb.PersistentClient(path=self.db_path)
        self.collection = self.chroma_client.get_or_create_collection(
            name="rockford_knowledge",
            embedding_function=self.openai_ef
        )

    def inject_knowledge_base(self, extracted_data):
        """دریافت داده‌های کرالر، خرد کردن متون بزرگ و تزریق به دیتابیس برداری"""
        ids = []
        documents = []
        metadatas = []
        
        counter = 0
        for item in extracted_data:
            content = item["content"]
            source = item["source"]
            content_type = item["type"]
            
            # خرد کردن متون طولانی (مثل داکیومنت‌ها) به بخش‌های ۱۰۰۰ کاراکتری برای درک دقیق‌تر هوش مصنوعی
            chunks = [content[i:i+1000] for i in range(0, len(content), 1000)]
            
            for idx, chunk in enumerate(chunks):
                counter += 1
                ids.append(f"id_{content_type}_{counter}_{idx}")
                documents.append(chunk)
                metadatas.append({"source": source, "type": content_type})
                
        if documents:
            # ذخیره‌سازی نهایی در مغز پایدار دیتابیس
            self.collection.upsert(
                ids=ids,
                documents=documents,
                metadatas=metadatas
            )
            print(f"[VECTOR-STORE] Successfully indexed {len(documents)} text chunks into Vector DB.")

    def query_relevant_knowledge(self, user_query, n_results=4):
        """جستجوی سریع معنایی بر اساس سوال کاربر"""
        try:
            results = self.collection.query(
                query_texts=[user_query],
                n_results=n_results
            )
            
            # چسباندن بخش‌های یافت شده برای ارسال به پرامپت مغز چت‌بات
            context_list = []
            if results and 'documents' in results and results['documents']:
                for doc_group in results['documents']:
                    for doc in doc_group:
                        context_list.append(doc)
                        
            return "\n\n---\n\n".join(context_list)
        except Exception as e:
            print(f"[VECTOR-QUERY-ERROR] {str(e)}")
            return ""