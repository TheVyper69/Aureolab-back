import { api } from './api.js';

class UsersService {
  async list() {
    return (await api.get('/users')).data;
  }

  async create(payload) {
    return (await api.post('/auth/register', payload)).data;
  }
}

export const usersService = new UsersService();