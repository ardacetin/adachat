export type UserRole = 'super_admin' | 'admin' | 'user';

export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string | null;
    role: UserRole;
};

export type Auth = {
    user: User;
};
