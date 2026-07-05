const WP_URL = 'https://wp.makeweb.online';

export type WPPost = {
    id: number;
    slug: string;
    title: { rendered: string };
    content: { rendered: string };
    excerpt: { rendered: string };
    date: string;
};

export async function getPosts(): Promise<WPPost[]> {
    const res = await fetch(`${WP_URL}/wp-json/wp/v2/posts`);

    if (!res.ok) {
        throw new Error(`Failed to fetch posts: ${res.status} ${res.statusText}`);
    }

    return res.json();
}

export async function getPostBySlug(slug: string): Promise<WPPost | null> {
    const res = await fetch(`${WP_URL}/wp-json/wp/v2/posts?slug=${slug}`);

    if (!res.ok) {
        throw new Error(`Failed to fetch post: ${res.status} ${res.statusText}`);
    }

    const posts: WPPost[] = await res.json();
    return posts[0] ?? null;
}
